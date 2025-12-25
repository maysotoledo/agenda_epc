<?php

namespace App\Filament\Widgets;

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

        // auto seleciona o primeiro EPC (por nome)
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

        $this->refreshRecords();
    }

    public static function getHeading(): string
    {
        return 'Calendário';
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('epc')) {
            return true;
        }

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
        if (! $this->agendaUserId) {
            return null;
        }

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
        if (! $dia) {
            return $this->baseHourOptions();
        }

        if (! $this->agendaUserId) {
            return [];
        }

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

            // ✅ Se for fim de semana: modal só com mensagem
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

            // ✅ Se for bloqueio do admin: modal só com mensagem + motivo
            Placeholder::make('msg_bloqueio_admin')
                ->label('')
                ->visible(function (Get $get): bool {
                    $dia = $get('dia');
                    if (! $dia) return false;

                    // importante: bloqueio do admin apenas (não fim de semana)
                    return $this->isDiaUtil($dia) && $this->isDiaBloqueado($dia);
                })
                ->content(function (Get $get): string {
                    $dia = $get('dia');

                    $data = $dia ? Carbon::parse($dia)->format('d/m/Y') : '';
                    $bloqueio = $dia ? $this->getBloqueioDoDia($dia) : null;

                    $motivo = $bloqueio?->motivo ?: 'Sem motivo informado.';

                    return "🚫 Dia bloqueado para este EPC ({$data}).\nMotivo: {$motivo}";
                }),

            // ✅ A partir daqui, os campos só aparecem se NÃO estiver bloqueado/fds
            TextInput::make('titulo')
                ->label('Título')
                ->maxLength(255)
                ->required(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia'))))
                ->visible(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia')))),

            Select::make('hora_inicio')
                ->label('Horário')
                ->visible(fn (Get $get) => ! ($get('dia') && $this->isDiaBloqueadoOuFimDeSemana($get('dia'))))
                ->options(fn (Get $get) => $this->availableHourOptions(
                    $get('dia'),
                    $get('evento_id')
                ))
                ->disabled(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return false;

                    return empty($this->availableHourOptions($dia, $get('evento_id')));
                })
                ->required(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return true;

                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        return false;
                    }

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

                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        return false;
                    }

                    return ! empty($this->availableHourOptions($dia, $get('evento_id')));
                }),

            Hidden::make('ends_at')
                ->required(function (Get $get) {
                    $dia = $get('dia');
                    if (! $dia) return true;

                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        return false;
                    }

                    return ! empty($this->availableHourOptions($dia, $get('evento_id')));
                }),

            // ✅ desabilita submit quando não há starts_at (ou seja, bloqueado / sem horários)
            Placeholder::make('disable_submit_when_no_hours')
                ->label('')
                ->content('')
                ->extraAttributes([
                    'style' => 'display:none',
                    'x-data' => '{}',
                    'x-effect' => <<<'JS'
                        const root =
                            $el.closest('.fi-modal') ||
                            $el.closest('[role="dialog"]') ||
                            document;
                        const submit = root.querySelector('button[type="submit"]');
                        const startsAt = $wire.get('mountedActionsData.0.starts_at');
                        if (submit) {
                            submit.disabled = !startsAt;
                        }
                    JS,
                ]),
        ];
    }

    protected function headerActions(): array
    {
        return [
            FilamentAction::make('selecionarUsuario')
                ->label('Selecionar usuário')
                ->icon('heroicon-o-user')
                ->visible(fn () => ! auth()->user()?->hasRole('epc') && ! $this->agendaUserId)
                ->url(fn () => route('filament.admin.pages.dashboard')),

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

                    // ✅ Se bloqueado/fds: abre modal apenas com mensagem
                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,
                            'titulo' => null,
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
                            'titulo' => null,
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
                        'titulo' => null,
                    ]);
                })
                ->mutateFormDataUsing(function (array $data): array {
                    if (! $this->agendaUserId) {
                        throw ValidationException::withMessages([
                            'titulo' => 'Selecione um EPC para agendar.',
                        ]);
                    }

                    if (empty($data['starts_at'])) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Não há horário disponível para este dia.',
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
                }),
        ];
    }

    protected function modalActions(): array
    {
        return [
            Actions\EditAction::make()
                ->mountUsing(function (Schema $form, Model $record, array $arguments) {
                    /** @var Evento $record */
                    $start = Carbon::parse($record->starts_at);
                    $dia = $start->toDateString();

                    // ✅ Se bloqueado/fds: abre modal apenas com mensagem (sem editar)
                    if ($this->isDiaBloqueadoOuFimDeSemana($dia)) {
                        $form->fill([
                            'evento_id' => $record->getKey(),
                            'dia' => $dia,
                            'titulo' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);

                        return;
                    }

                    $hora = $start->format('H:00');
                    $end = $record->ends_at ? Carbon::parse($record->ends_at) : $start->copy()->addHour();

                    $form->fill([
                        'evento_id' => $record->getKey(),
                        'dia' => $dia,
                        'hora_inicio' => $hora,
                        'titulo' => $record->titulo,
                        'starts_at' => $start->toDateTimeString(),
                        'ends_at' => $end->toDateTimeString(),
                    ]);
                })
                ->mutateFormDataUsing(function (array $data, Model $record): array {
                    if (empty($data['starts_at'])) {
                        // bloqueado/fds: não deveria salvar
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Não é possível editar/agendar neste dia.',
                        ]);
                    }

                    $start = Carbon::parse($data['starts_at']);
                    $dia = $start->toDateString();

                    $this->assertDiaAgendavelOrThrow($dia);

                    $jaExiste = Evento::query()
                        ->where('user_id', $this->agendaUserId)
                        ->whereKeyNot($record->getKey())
                        ->whereDate('starts_at', $dia)
                        ->whereTime('starts_at', $start->format('H:i:s'))
                        ->exists();

                    if ($jaExiste) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Este horário já foi agendado para este usuário. Selecione outro.',
                        ]);
                    }

                    unset($data['dia'], $data['hora_inicio'], $data['evento_id']);

                    return $data;
                }),

            Actions\DeleteAction::make(),
        ];
    }

    private function getBlockedDaysInRange(Carbon $start, Carbon $end): Collection
    {
        if (! $this->agendaUserId) {
            return collect();
        }

        return Bloqueio::query()
            ->where('user_id', $this->agendaUserId)
            ->whereDate('dia', '>=', $start->toDateString())
            ->whereDate('dia', '<=', $end->toDateString())
            ->pluck('dia');
    }

    public function fetchEvents(array $fetchInfo): array
    {
        if (! $this->agendaUserId) {
            return [];
        }

        $rangeStart = Carbon::parse($fetchInfo['start'])->startOfDay();
        $rangeEnd = Carbon::parse($fetchInfo['end'])->endOfDay();

        // eventos normais
        $agendamentos = Evento::query()
            ->where('user_id', $this->agendaUserId)
            ->where('starts_at', '<', $rangeEnd)
            ->where(function ($q) use ($rangeStart) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $rangeStart);
            })
            ->get()
            ->map(fn (Evento $e) => [
                'id' => (string) $e->id,
                'title' => $e->titulo,
                'start' => $e->starts_at,
                'end' => $e->ends_at,
            ])
            ->all();

        // bloqueios em background vermelho + finais de semana em background padrão
        $blockedDays = $this->getBlockedDaysInRange($rangeStart, $rangeEnd)
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->values();

        $background = [];

        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $day = $cursor->toDateString();

            if ($cursor->isWeekend()) {
                $background[] = [
                    'id' => 'weekend-' . $day,
                    'start' => $day,
                    'end' => $cursor->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'display' => 'background',
                     'backgroundColor' => 'rgba(230, 113, 113, 0.96)',
                    'borderColor' => 'rgba(255, 0, 0, 0.35)',
                ];
            }

            if ($blockedDays->contains($day)) {
                $background[] = [
                    'id' => 'blocked-' . $day,
                    'start' => $day,
                    'end' => $cursor->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => 'rgba(230, 113, 113, 0.96)',
                    'borderColor' => 'rgba(255, 0, 0, 0.35)',
                ];
            }

            $cursor->addDay();
        }

        return array_merge($agendamentos, $background);
    }
}
