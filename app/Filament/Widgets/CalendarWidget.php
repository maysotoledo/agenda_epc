<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Agendas\AgendaResource;
use App\Models\Bloqueio;
use App\Models\Evento;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Actions\Action as FilamentAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Saade\FilamentFullCalendar\Actions;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class CalendarWidget extends FullCalendarWidget
{
    use HasWidgetShield;

    public Model|string|null $model = Evento::class;

    protected static ?int $sort = 2;

    public ?int $agendaUserId = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->hasRole('epc')) {
            $this->agendaUserId = (int) $user->getKey();
            session(['agenda_user_id' => $this->agendaUserId]);
            return;
        }

        if (! User::query()->role('epc')->exists()) {
            $this->agendaUserId = null;
            session()->forget('agenda_user_id');
            return;
        }

        $sessionUserId = session('agenda_user_id');

        $validSessionUserId = User::query()
            ->role('epc')
            ->whereKey($sessionUserId)
            ->value('id');

        if ($validSessionUserId) {
            $this->agendaUserId = (int) $validSessionUserId;
            return;
        }

        $firstEpcId = (int) User::query()
            ->role('epc')
            ->orderBy('name')
            ->value('id');

        $this->agendaUserId = $firstEpcId ?: null;

        if ($this->agendaUserId) {
            session(['agenda_user_id' => $this->agendaUserId]);
        } else {
            session()->forget('agenda_user_id');
        }
    }

    #[On('agendaUserSelected')]
    public function setAgendaUser(int $userId): void
    {
        if (auth()->user()?->hasRole('epc')) {
            return;
        }

        $isEpc = User::query()->role('epc')->whereKey($userId)->exists();
        if (! $isEpc) {
            return;
        }

        $this->agendaUserId = $userId;
        session(['agenda_user_id' => $userId]);

        $this->forceCalendarRefresh();
    }

    private function forceCalendarRefresh(): void
    {
        $this->refreshRecords();
        $this->dispatch('$refresh');
    }

    public static function getHeading(): string
    {
        return 'Calendário';
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) return false;

        if ($user->hasRole('epc')) return true;

        return User::query()->role('epc')->exists();
    }

    public function config(): array
    {
        return [
            'selectable' => true,
            'unselectAuto' => true,
            'firstDay' => 1,
            'editable' => true,
            'locale' => 'pt-br',

            // Hora vai no title (ex: 14h Mayso), então não repete hora automática
            'displayEventTime' => false,

            'slotLabelFormat' => [
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,
            ],
            'eventTimeFormat' => [
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,
            ],

            // Tooltip no hover: mostra número do procedimento
            'eventDidMount' => RawJs::make(<<<'JS'
function(info) {
    if (info.event.display === 'background') return;

    const proc =
        info.event?.extendedProps?.procedimento
        || info.event?._def?.extendedProps?.procedimento;

    if (!proc) return;

    info.el.setAttribute('title', proc);

    const anchor = info.el.querySelector('a');
    if (anchor) anchor.setAttribute('title', proc);

    const main = info.el.querySelector('.fc-event-main');
    if (main) main.setAttribute('title', proc);

    const title = info.el.querySelector('.fc-event-title');
    if (title) title.setAttribute('title', proc);
}
JS),
        ];
    }

    public function dateClick(): string
    {
        return <<<JS
            function(info) {
                info.view.calendar.el.__livewire.mountAction('create', {
                    start: info.dateStr,
                    end: info.dateStr
                });
            }
        JS;
    }

    private function isDiaUtil(string $dia): bool
    {
        return ! Carbon::parse($dia)->isWeekend();
    }

    private function getBloqueioDoDia(string $dia): ?Bloqueio
    {
        if (! $this->agendaUserId) return null;

        return Bloqueio::query()
            ->where('user_id', $this->agendaUserId)
            ->whereDate('dia', $dia)
            ->first();
    }

    private function isDiaBloqueado(string $dia): bool
    {
        return (bool) $this->getBloqueioDoDia($dia);
    }

    private function isDiaBloqueadoOuFimDeSemana(string $dia): bool
    {
        return (! $this->isDiaUtil($dia)) || $this->isDiaBloqueado($dia);
    }

    private function baseHourOptions(): array
    {
        $hours = array_merge(range(8, 11), range(14, 17));
        $options = [];

        foreach ($hours as $h) {
            $options[sprintf('%02d:00', $h)] = sprintf('%02d:00', $h);
        }

        return $options;
    }

    private function availableHourOptions(?string $dia, ?int $ignoreEventoId = null): array
    {
        if (! $dia) return $this->baseHourOptions();
        if (! $this->agendaUserId) return [];

        if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
            return [];
        }

        $options = $this->baseHourOptions();

        $ocupados = Evento::query()
            ->where('user_id', $this->agendaUserId)
            ->whereDate('starts_at', $dia)
            ->when($ignoreEventoId, fn ($q) => $q->whereKeyNot($ignoreEventoId))
            ->pluck('starts_at')
            ->map(fn ($dt) => Carbon::parse($dt)->format('H:00'))
            ->unique()
            ->all();

        foreach ($ocupados as $hora) {
            unset($options[$hora]);
        }

        return $options;
    }

    private function assertDiaAgendavelOrThrow(string $dia): void
    {
        if (! $this->isDiaUtil($dia)) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'Agendamentos apenas em dias úteis (segunda a sexta).',
            ]);
        }

        if ($this->isDiaBloqueado($dia)) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'Este dia está bloqueado para este EPC.',
            ]);
        }
    }

    public function getFormSchema(): array
    {
        return [
            Hidden::make('evento_id'),
            Hidden::make('dia')->dehydrated(false),

            Placeholder::make('msg_fim_de_semana')
                ->label('')
                ->visible(function (Get $get): bool {
                    $dia = $get('dia');
                    if (! $dia) return false;
                    return Carbon::parse($dia)->isWeekend();
                })
                ->content(function (Get $get): string {
                    $dia = $get('dia');
                    $data = $dia ? Carbon::parse($dia)->format('d/m/Y') : '';
                    return "❌ {$data} é fim de semana.\nAgendamentos somente em dias úteis (segunda a sexta).";
                }),

            Placeholder::make('msg_bloqueio_admin')
                ->label('')
                ->visible(function (Get $get): bool {
                    $dia = $get('dia');
                    if (! $dia) return false;
                    return $this->isDiaUtil($dia) && $this->isDiaBloqueado($dia);
                })
                ->content(function (Get $get): string {
                    $dia = $get('dia');
                    $data = $dia ? Carbon::parse($dia)->format('d/m/Y') : '';
                    $bloqueio = $dia ? $this->getBloqueioDoDia($dia) : null;
                    $motivo = $bloqueio?->motivo ?: 'Sem motivo informado.';
                    return "🚫 Dia bloqueado para este EPC ({$data}).\nMotivo: {$motivo}";
                }),

            TextInput::make('intimado')
                ->label('Intimado')
                ->maxLength(255)
                ->required(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia'))))
                ->visible(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia')))),

            TextInput::make('numero_procedimento')
                ->label('Número do procedimento')
                ->maxLength(80)
                ->required(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia'))))
                ->visible(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia')))),

            Select::make('hora_inicio')
                ->label('Horário')
                ->visible(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia'))))
                ->options(fn (Get $get) => $this->availableHourOptions($get('dia'), $get('evento_id')))
                ->disabled(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return false;
                    return empty($this->availableHourOptions($dia, $get('evento_id')));
                })
                ->required(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return true;
                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) return false;
                    return ! empty($this->availableHourOptions($dia, $get('evento_id')));
                })
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                    $dia = $get('dia');
                    if (! $dia || ! $state) return;

                    $inicio = Carbon::parse("{$dia} {$state}");
                    $fim = $inicio->copy()->addHour();

                    $set('starts_at', $inicio->toDateTimeString());
                    $set('ends_at', $fim->toDateTimeString());
                }),

            Hidden::make('starts_at')
                ->required(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return true;
                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) return false;
                    return ! empty($this->availableHourOptions($dia, $get('evento_id')));
                }),

            Hidden::make('ends_at')
                ->required(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return true;
                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) return false;
                    return ! empty($this->availableHourOptions($dia, $get('evento_id')));
                }),
        ];
    }

    protected function headerActions(): array
    {
        return [
            FilamentAction::make('selecionarUsuario')
                ->label('Selecionar usuário')
                ->icon('heroicon-o-user')
                ->visible(fn () => ! auth()->user()?->hasRole('epc') && ! $this->agendaUserId)
                ->url(fn () => AgendaResource::getUrl('index')),

            Actions\CreateAction::make()
                ->label('Agendar')
                ->mountUsing(function (Schema $form, array $arguments) {
                    if (! $this->agendaUserId) {
                        Notification::make()
                            ->title('Sem EPC selecionado')
                            ->body('Selecione um EPC para visualizar/agendar.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $dia = isset($arguments['start'])
                        ? Carbon::parse($arguments['start'])->toDateString()
                        : null;

                    if (! $dia) {
                        Notification::make()
                            ->title('Selecione um dia')
                            ->body('Clique em um dia no calendário para agendar.')
                            ->warning()
                            ->send();
                        return;
                    }

                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,
                            'intimado' => null,
                            'numero_procedimento' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);
                        return;
                    }

                    $options = $this->availableHourOptions($dia);

                    if (empty($options)) {
                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,
                            'intimado' => null,
                            'numero_procedimento' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);
                        return;
                    }

                    $hora = array_key_first($options);
                    $inicio = Carbon::parse("{$dia} {$hora}");
                    $fim = $inicio->copy()->addHour();

                    $form->fill([
                        'evento_id' => null,
                        'dia' => $dia,
                        'hora_inicio' => $hora,
                        'starts_at' => $inicio->toDateTimeString(),
                        'ends_at' => $fim->toDateTimeString(),
                        'intimado' => null,
                        'numero_procedimento' => null,
                    ]);
                })
                ->mutateFormDataUsing(function (array $data): array {
                    if (! $this->agendaUserId) {
                        throw ValidationException::withMessages([
                            'intimado' => 'Selecione um EPC para agendar.',
                        ]);
                    }

                    if (empty($data['starts_at'])) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Não é possível agendar neste dia.',
                        ]);
                    }

                    $start = Carbon::parse($data['starts_at']);
                    $dia = $start->toDateString();

                    $this->assertDiaAgendavelOrThrow($dia);

                    $jaExiste = Evento::query()
                        ->where('user_id', $this->agendaUserId)
                        ->whereDate('starts_at', $dia)
                        ->whereTime('starts_at', $start->format('H:i:s'))
                        ->exists();

                    if ($jaExiste) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Este horário já foi agendado para este usuário. Selecione outro.',
                        ]);
                    }

                    $data['user_id'] = $this->agendaUserId;

                    unset($data['dia'], $data['hora_inicio'], $data['evento_id']);

                    return $data;
                })
                ->after(function (): void {
                    // ✅ Observer notifica o EPC
                    // ✅ aqui a gente só força o refresh pra aparecer sem F5
                    $this->forceCalendarRefresh();
                }),
        ];
    }

    protected function modalActions(): array
    {
        return [
            Actions\EditAction::make()
                ->after(function (): void {
                    $this->forceCalendarRefresh();
                }),

            Actions\DeleteAction::make()
                ->after(function (): void {
                    $this->forceCalendarRefresh();
                }),
        ];
    }

    private function getBlockedDaysInRange(Carbon $start, Carbon $end): Collection
    {
        if (! $this->agendaUserId) return collect();

        return Bloqueio::query()
            ->where('user_id', $this->agendaUserId)
            ->whereDate('dia', '>=', $start->toDateString())
            ->whereDate('dia', '<=', $end->toDateString())
            ->pluck('dia');
    }

    public function fetchEvents(array $fetchInfo): array
    {
        if (! $this->agendaUserId) return [];

        $rangeStart = Carbon::parse($fetchInfo['start'])->startOfDay();
        $rangeEnd = Carbon::parse($fetchInfo['end'])->endOfDay();

        $eventos = Evento::query()
            ->where('user_id', $this->agendaUserId)
            ->where('starts_at', '<', $rangeEnd)
            ->where(function ($q) use ($rangeStart) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $rangeStart);
            })
            ->get();

        $diasComAgendamento = $eventos
            ->map(fn (Evento $e) => Carbon::parse($e->starts_at)->toDateString())
            ->unique()
            ->values();

        $agendamentos = $eventos
            ->map(function (Evento $e) {
                $intimado = $e->intimado ?: 'Agendamento';

                // exemplo: 14h Mayso
                $hora = $e->starts_at ? Carbon::parse($e->starts_at)->format('G') : '--';
                $title = "{$hora}h {$intimado}";

                $proc = $e->numero_procedimento ?: '—';

                return [
                    'id' => (string) $e->id,
                    'title' => $title,
                    'start' => $e->starts_at,
                    'end' => $e->ends_at,
                    'procedimento' => "Procedimento: {$proc}",
                ];
            })
            ->all();

        $blockedDays = $this->getBlockedDaysInRange($rangeStart, $rangeEnd)
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->values();

        $background = [];

        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $day = $cursor->toDateString();

            // fim de semana (vermelho)
            if ($cursor->isWeekend()) {
                $background[] = [
                    'id' => 'weekend-' . $day,
                    'start' => $day,
                    'end' => $cursor->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => 'rgba(239, 81, 81, 1)',
                    'borderColor' => 'rgba(255, 0, 0, 0.35)',
                ];

                $cursor->addDay();
                continue;
            }

            // bloqueio (vermelho)
            if ($blockedDays->contains($day)) {
                $background[] = [
                    'id' => 'blocked-' . $day,
                    'start' => $day,
                    'end' => $cursor->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => 'rgba(239, 81, 81, 1)',
                    'borderColor' => 'rgba(255, 0, 0, 0.35)',
                ];

                $cursor->addDay();
                continue;
            }

            // dia com agendamento (verde)
            if ($diasComAgendamento->contains($day)) {
                $background[] = [
                    'id' => 'busy-' . $day,
                    'start' => $day,
                    'end' => $cursor->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => 'rgba(0, 128, 0, 1)',
                    'borderColor' => 'rgba(0, 128, 0, 0.28)',
                ];
            }

            $cursor->addDay();
        }

        return array_merge($agendamentos, $background);
    }
}
