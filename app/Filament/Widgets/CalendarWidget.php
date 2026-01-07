<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Agendas\AgendaResource;
use App\Models\Bloqueio;
use App\Models\Evento;
use App\Models\User;
use App\Services\EventoService;
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
use Illuminate\Support\Facades\Gate;
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

    /**
     * ✅ Controla se o modal atual é "somente mensagem".
     * (sem horário / fim de semana / bloqueio / sem permissão)
     * Usado para esconder o Submit e mostrar o botão "Fechar".
     */
    public bool $modalSemHorario = false;

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

    public function forceCalendarRefresh(): void
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

    /**
     * ✅ Permite abrir o modal de edição/cancelamento:
     * admin OU criador OU permissão do Shield (update/delete).
     */
    public function canManageEvento(int|string $eventoId): bool
    {
        /** @var \App\Models\Evento|null $evento */
        $evento = Evento::query()->find($eventoId);
        if (! $evento) return false;

        $user = auth()->user();
        if (! $user) return false;

        $isAdmin = (bool) $user->hasRole('super_admin');
        $isCreator = ((int) $evento->created_by === (int) $user->getKey());

        return $isAdmin
            || $isCreator
            || Gate::allows('update', $evento)
            || Gate::allows('delete', $evento);
    }

    /**
     * ✅ Polling a cada 10s SOMENTE quando a aba estiver visível.
     * ✅ Ação: calendar.refetchEvents() (isso puxa novamente o fetchEvents()).
     */
    public function viewDidMount(): string
    {
        return <<<'JS'
function(arg) {
    const calendar = arg?.view?.calendar;
    const calEl = calendar?.el;

    if (!calendar || !calEl) return;

    // evita instalar 2x
    if (calEl.dataset.pollVisibleInstalled === '1') return;
    calEl.dataset.pollVisibleInstalled = '1';

    // key por componente (SPA safe)
    const root = calEl.closest('[wire\\:id]');
    const key = root?.getAttribute('wire:id') || 'calendar';

    window.__agendaCalendarTimers = window.__agendaCalendarTimers || {};

    // se já tinha timer antigo (troca de página / hot reload), limpa
    if (window.__agendaCalendarTimers[key]) {
        clearInterval(window.__agendaCalendarTimers[key]);
    }

    const tick = () => {
        if (document.visibilityState !== 'visible') return;

        try {
            // ✅ aqui é o "segredo": força o FullCalendar a buscar novamente
            calendar.refetchEvents();
        } catch (e) {
            // opcional: console.debug('refetchEvents failed', e);
        }
    };

    // roda já ao montar
    tick();

    // inicia timer
    window.__agendaCalendarTimers[key] = setInterval(tick, 10000);

    // ao voltar pra aba, atualiza imediatamente
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') tick();
    });

    window.addEventListener('focus', tick);
}
JS;
    }

    public function config(): array
    {
        return [
            'selectable' => true,
            'unselectAuto' => true,
            'firstDay' => 1,
            'editable' => true,
            'locale' => 'pt-br',

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

            // ✅ Clique no evento: se não tiver permissão, abre o modal já com o aviso.
            'eventClick' => RawJs::make(<<<'JS'
function(info) {
    const lw = info?.view?.calendar?.el?.__livewire;
    if (!lw) return;

    if (info?.jsEvent?.preventDefault) info.jsEvent.preventDefault();

    const eventId = info.event?.id;
    if (!eventId) return;

    lw.call('canManageEvento', eventId).then((can) => {
        if (can) {
            lw.mountAction('edit', { event: { id: eventId } });
            return;
        }

        lw.mountAction('edit', {
            event: { id: eventId },
            noPermission: true
        });
    });
}
JS),

            // ✅ Arrastar: abre o edit. Se não puder, abre o modal já com aviso.
            'eventDrop' => RawJs::make(<<<'JS'
function(info) {
    const lw = info?.view?.calendar?.el?.__livewire;
    if (!lw) return;

    if (typeof info.revert === 'function') info.revert();

    const eventId = info.event?.id;
    const startStr =
        info.event?.startStr
        || (info.event?.start ? info.event.start.toISOString() : null);

    lw.call('canManageEvento', eventId).then((can) => {
        if (can) {
            lw.mountAction('edit', {
                event: {
                    id: eventId,
                    start: startStr,
                    end: info.event?.endStr || null,
                }
            });
            return;
        }

        lw.mountAction('edit', {
            event: {
                id: eventId,
                start: startStr,
                end: info.event?.endStr || null,
            },
            noPermission: true
        });
    });
}
JS),
        ];
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
function({ event, el }) {
    if (event.display === 'background') return;

    const content =
        event?.extendedProps?.procedimento
        ?? event?.title
        ?? '';

    if (!content) return;

    el.setAttribute('x-tooltip', 'tooltip');
    el.setAttribute('x-data', '{ tooltip: ' + JSON.stringify(content) + ' }');
    el.setAttribute('title', content);

    if (window.Alpine && typeof window.Alpine.initTree === 'function') {
        window.Alpine.initTree(el);
    }
}
JS;
    }

    public function dateClick(): string
    {
        return <<<JS
function(info) {
    const lw = info?.view?.calendar?.el?.__livewire;
    if (!lw) return;

    lw.mountAction('create', {
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

    private function makeSemHorarioMessage(string $dia): string
    {
        $data = Carbon::parse($dia)->format('d/m/Y');
        return "❌ Não há horários disponíveis em {$data} para este EPC.";
    }

    private function makeFimDeSemanaMessage(string $dia): string
    {
        $data = Carbon::parse($dia)->format('d/m/Y');
        return "❌ {$data} é fim de semana.\nAgendamentos somente em dias úteis (segunda a sexta).";
    }

    private function makeBloqueioMessage(string $dia): string
    {
        $data = Carbon::parse($dia)->format('d/m/Y');
        $bloqueio = $this->getBloqueioDoDia($dia);
        $motivo = $bloqueio?->motivo ?: 'Sem motivo informado.';
        return "🚫 Dia bloqueado para este EPC ({$data}).\nMotivo: {$motivo}";
    }

    public function getFormSchema(): array
    {
        return [
            Hidden::make('evento_id'),
            Hidden::make('dia')->dehydrated(false),

            Hidden::make('somente_msg')->dehydrated(false),
            Hidden::make('somente_msg_texto')->dehydrated(false),

            Hidden::make('sem_horario')->dehydrated(false),
            Hidden::make('sem_horario_msg')->dehydrated(false),

            Placeholder::make('msg_somente')
                ->label('⚠️ Aviso!')
                ->visible(fn (Get $get): bool => (bool) $get('somente_msg'))
                ->content(fn (Get $get): string => (string) ($get('somente_msg_texto') ?: '')),

            TextInput::make('intimado')
                ->label('Intimado')
                ->maxLength(255)
                ->required(fn (Get $get) => ! $get('somente_msg'))
                ->visible(fn (Get $get) => ! $get('somente_msg')),

            TextInput::make('numero_procedimento')
                ->label('Número do procedimento')
                ->maxLength(80)
                ->required(fn (Get $get) => ! $get('somente_msg'))
                ->visible(fn (Get $get) => ! $get('somente_msg')),

            Select::make('hora_inicio')
                ->label('Horário')
                ->visible(fn (Get $get) => ! $get('somente_msg'))
                ->options(fn (Get $get) => $this->availableHourOptions($get('dia'), $get('evento_id')))
                ->disabled(fn (Get $get) => (bool) $get('somente_msg'))
                ->required(fn (Get $get) => ! $get('somente_msg'))
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                    if ($get('somente_msg')) return;

                    $dia = $get('dia');
                    if (! $dia || ! $state) return;

                    $inicio = Carbon::parse("{$dia} {$state}");
                    $fim = $inicio->copy()->addHour();

                    $set('starts_at', $inicio->toDateTimeString());
                    $set('ends_at', $fim->toDateTimeString());
                }),

            Hidden::make('starts_at')
                ->required(fn (Get $get) => ! $get('somente_msg')),

            Hidden::make('ends_at')
                ->required(fn (Get $get) => ! $get('somente_msg')),
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
                ->createAnother(false)
                ->modalSubmitAction(fn (\Filament\Actions\Action $action) => $action->visible(fn (): bool => ! $this->modalSemHorario))
                ->modalCancelAction(function (\Filament\Actions\Action $action) {
                    return $action->label($this->modalSemHorario ? 'Fechar' : 'Cancelar');
                })
                ->modalCloseButton(true)

                ->mountUsing(function (Schema $form, array $arguments) {
                    $this->modalSemHorario = false;

                    if (! $this->agendaUserId) {
                        Notification::make()
                            ->title('Sem EPC selecionado')
                            ->body('Selecione um EPC para visualizar/agendar.')
                            ->warning()
                            ->send();

                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => null,
                            'dia' => null,

                            'somente_msg' => true,
                            'somente_msg_texto' => '⚠️ Selecione um EPC para visualizar/agendar.',

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'intimado' => null,
                            'numero_procedimento' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);
                        return;
                    }

                    $dia = isset($arguments['start'])
                        ? Carbon::parse($arguments['start'])->toDateString()
                        : null;

                    if (! $dia) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => null,
                            'dia' => null,

                            'somente_msg' => true,
                            'somente_msg_texto' => '⚠️ Clique em um dia no calendário para agendar.',

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'intimado' => null,
                            'numero_procedimento' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);
                        return;
                    }

                    if (Carbon::parse($dia)->isWeekend()) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeFimDeSemanaMessage($dia),

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'intimado' => null,
                            'numero_procedimento' => null,
                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);
                        return;
                    }

                    if ($this->isDiaBloqueado($dia)) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeBloqueioMessage($dia),

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

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
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => null,
                            'dia' => $dia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeSemHorarioMessage($dia),

                            'sem_horario' => true,
                            'sem_horario_msg' => $this->makeSemHorarioMessage($dia),

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

                        'somente_msg' => false,
                        'somente_msg_texto' => null,

                        'sem_horario' => false,
                        'sem_horario_msg' => null,

                        'hora_inicio' => $hora,
                        'starts_at' => $inicio->toDateTimeString(),
                        'ends_at' => $fim->toDateTimeString(),
                        'intimado' => null,
                        'numero_procedimento' => null,
                    ]);
                })
                ->mutateFormDataUsing(function (array $data): array {
                    if ($this->modalSemHorario || ($data['somente_msg'] ?? false)) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => '❌ Não é possível concluir esta ação.',
                        ]);
                    }

                    if (! $this->agendaUserId) {
                        throw ValidationException::withMessages([
                            'intimado' => 'Selecione um EPC para agendar.',
                        ]);
                    }

                    if (empty($data['starts_at'])) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => '❌ Não é possível agendar neste dia.',
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
                            'hora_inicio' => '❌ Este horário já foi agendado para este usuário. Selecione outro.',
                        ]);
                    }

                    $data['user_id'] = $this->agendaUserId;
                    $data['created_by'] = auth()->id();

                    unset(
                        $data['dia'],
                        $data['hora_inicio'],
                        $data['evento_id'],
                        $data['sem_horario'],
                        $data['sem_horario_msg'],
                        $data['somente_msg'],
                        $data['somente_msg_texto'],
                    );

                    return $data;
                })
                ->using(function (array $data) {
                    return app(EventoService::class)->criar($data);
                })
                ->after(function (): void {
                    $this->forceCalendarRefresh();
                }),
        ];
    }

    protected function modalActions(): array
    {
        return [
            Actions\EditAction::make()
                ->modalSubmitAction(fn (\Filament\Actions\Action $action) => $action->visible(fn (): bool => ! $this->modalSemHorario))
                ->modalCancelAction(function (\Filament\Actions\Action $action) {
                    return $action->label($this->modalSemHorario ? 'Fechar' : 'Cancelar');
                })
                ->modalCloseButton(true)

                ->mountUsing(function (Model $record, Schema $form, array $arguments) {
                    /** @var \App\Models\Evento $record */
                    $this->modalSemHorario = false;

                    $user = auth()->user();
                    $isAdmin = (bool) ($user?->hasRole('admin'));
                    $isCreator = $user && ((int) $record->created_by === (int) $user->getKey());

                    $canEdit = $isAdmin || $isCreator || Gate::allows('update', $record);

                    if (($arguments['noPermission'] ?? false) === true) {
                        $this->modalSemHorario = true;

                        $dia = $record->starts_at ? Carbon::parse($record->starts_at)->toDateString() : null;

                        $form->fill([
                            'evento_id' => $record->id,
                            'dia' => $dia,

                            'somente_msg' => true,
                            'somente_msg_texto' => '❌ Você não tem permissão para editar/cancelar este agendamento.',

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,

                            'intimado' => $record->intimado,
                            'numero_procedimento' => $record->numero_procedimento,
                        ]);

                        $this->forceCalendarRefresh();
                        return;
                    }

                    if (! $canEdit) {
                        $this->modalSemHorario = true;

                        $dia = $record->starts_at ? Carbon::parse($record->starts_at)->toDateString() : null;

                        $form->fill([
                            'evento_id' => $record->id,
                            'dia' => $dia,

                            'somente_msg' => true,
                            'somente_msg_texto' => '❌ Você não tem permissão para editar este agendamento.',

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,

                            'intimado' => $record->intimado,
                            'numero_procedimento' => $record->numero_procedimento,
                        ]);

                        $this->forceCalendarRefresh();
                        return;
                    }

                    $startArg = $arguments['event']['start'] ?? $record->starts_at;
                    $targetStart = Carbon::parse($startArg);
                    $targetDia = $targetStart->toDateString();

                    if (Carbon::parse($targetDia)->isWeekend()) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => $record->id,
                            'dia' => $targetDia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeFimDeSemanaMessage($targetDia),

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                            'intimado' => $record->intimado,
                            'numero_procedimento' => $record->numero_procedimento,
                        ]);

                        $this->forceCalendarRefresh();
                        return;
                    }

                    if ($this->isDiaBloqueado($targetDia)) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => $record->id,
                            'dia' => $targetDia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeBloqueioMessage($targetDia),

                            'sem_horario' => false,
                            'sem_horario_msg' => null,

                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                            'intimado' => $record->intimado,
                            'numero_procedimento' => $record->numero_procedimento,
                        ]);

                        $this->forceCalendarRefresh();
                        return;
                    }

                    $options = $this->availableHourOptions($targetDia, (int) $record->id);

                    if (empty($options)) {
                        $this->modalSemHorario = true;

                        $form->fill([
                            'evento_id' => $record->id,
                            'dia' => $targetDia,

                            'somente_msg' => true,
                            'somente_msg_texto' => $this->makeSemHorarioMessage($targetDia),

                            'sem_horario' => true,
                            'sem_horario_msg' => $this->makeSemHorarioMessage($targetDia),

                            'hora_inicio' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                            'intimado' => $record->intimado,
                            'numero_procedimento' => $record->numero_procedimento,
                        ]);

                        $this->forceCalendarRefresh();
                        return;
                    }

                    $originalDia = Carbon::parse($record->starts_at)->toDateString();

                    if ($targetDia !== $originalDia) {
                        $hora = array_key_first($options);
                    } else {
                        $candidate = $targetStart->format('H:00');
                        $hora = isset($options[$candidate]) ? $candidate : array_key_first($options);
                    }

                    $inicio = Carbon::parse("{$targetDia} {$hora}");
                    $fim = $inicio->copy()->addHour();

                    $form->fill([
                        'evento_id' => $record->id,
                        'dia' => $targetDia,

                        'somente_msg' => false,
                        'somente_msg_texto' => null,

                        'sem_horario' => false,
                        'sem_horario_msg' => null,

                        'hora_inicio' => $hora,
                        'starts_at' => $inicio->toDateTimeString(),
                        'ends_at' => $fim->toDateTimeString(),
                        'intimado' => $record->intimado,
                        'numero_procedimento' => $record->numero_procedimento,
                    ]);
                })
                ->mutateFormDataUsing(function (array $data): array {
                    if ($this->modalSemHorario || ($data['somente_msg'] ?? false)) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => '❌ Não é possível concluir esta ação.',
                        ]);
                    }

                    if (! $this->agendaUserId) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => 'Selecione um EPC para editar este agendamento.',
                        ]);
                    }

                    if (empty($data['starts_at'])) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => '❌ Não há horários disponíveis para este dia.',
                        ]);
                    }

                    $start = Carbon::parse($data['starts_at']);
                    $dia = $start->toDateString();

                    $this->assertDiaAgendavelOrThrow($dia);

                    $eventoId = (int) ($data['evento_id'] ?? 0);

                    $jaExiste = Evento::query()
                        ->where('user_id', $this->agendaUserId)
                        ->whereDate('starts_at', $dia)
                        ->whereTime('starts_at', $start->format('H:i:s'))
                        ->when($eventoId, fn ($q) => $q->whereKeyNot($eventoId))
                        ->exists();

                    if ($jaExiste) {
                        throw ValidationException::withMessages([
                            'hora_inicio' => '❌ Este horário já foi agendado para este usuário. Selecione outro.',
                        ]);
                    }

                    $data['user_id'] = $this->agendaUserId;

                    unset(
                        $data['dia'],
                        $data['hora_inicio'],
                        $data['evento_id'],
                        $data['sem_horario'],
                        $data['sem_horario_msg'],
                        $data['somente_msg'],
                        $data['somente_msg_texto'],
                    );

                    return $data;
                })
                ->using(function (Model $record, array $data) {
                    /** @var \App\Models\Evento $record */
                    $user = auth()->user();
                    $isAdmin = (bool) ($user?->hasRole('admin'));
                    $isCreator = $user && ((int) $record->created_by === (int) $user->getKey());

                    abort_unless($isAdmin || $isCreator || Gate::allows('update', $record), 403);

                    return app(EventoService::class)->editar($record, $data);
                })
                ->after(function (): void {
                    $this->forceCalendarRefresh();
                }),

            Actions\DeleteAction::make()
                ->label('Cancelar')
                ->visible(function (Model $record): bool {
                    /** @var \App\Models\Evento $record */
                    $user = auth()->user();
                    $isAdmin = (bool) ($user?->hasRole('admin'));
                    $isCreator = $user && ((int) $record->created_by === (int) $user->getKey());

                    return $isAdmin || $isCreator || Gate::allows('delete', $record);
                })
                ->modalHeading('Cancelar agendamento?')
                ->modalDescription('O cancelamento preserva o histórico e pode ser restaurado depois.')
                ->action(function (Model $record): void {
                    /** @var \App\Models\Evento $record */
                    $user = auth()->user();
                    $isAdmin = (bool) ($user?->hasRole('admin'));
                    $isCreator = $user && ((int) $record->created_by === (int) $user->getKey());

                    abort_unless($isAdmin || $isCreator || Gate::allows('delete', $record), 403);

                    app(EventoService::class)->cancelar($record);
                })
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
                $proc = $e->numero_procedimento ?: null;

                $hora = $e->starts_at ? Carbon::parse($e->starts_at)->format('G') : '--';

                $title = "{$hora}h {$intimado}" . ($proc ? " {$proc}" : '');

                return [
                    'id' => (string) $e->id,
                    'title' => $title,
                    'start' => $e->starts_at,
                    'end' => $e->ends_at,
                    'procedimento' => $proc ? "Procedimento: {$proc}" : null,
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
