<?php

namespace App\Filament\Widgets;

use App\Models\Ferias;
use App\Models\User;
use App\Services\FeriasService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Saade\FilamentFullCalendar\Actions;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class FeriasCalendarWidget extends FullCalendarWidget
{
    public Model|string|null $model = Ferias::class;

    protected static ?int $sort = 1;

    public static function getHeading(): string
    {
        return 'Calendário de Férias';
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();
        return (bool) $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    private function calcularFim(?string $inicio, ?int $qtd): ?string
    {
        if (! $inicio || ! $qtd || $qtd < 1) return null;

        $start = Carbon::parse($inicio)->startOfDay();
        return $start->copy()->addDays($qtd - 1)->toDateString();
    }

    private function corPorRole(?User $user): string
    {
        if (! $user) return '#6b7280';

        if ($user->hasRole('ipc_plantao')) return '#f97316'; // laranja
        if ($user->hasRole('epc_plantao')) return '#facc15'; // amarelo

        if ($user->hasRole('ipc')) return '#2563eb'; // azul
        if ($user->hasRole('epc')) return '#16a34a'; // verde

        return '#6b7280';
    }

    public function config(): array
    {
        return [
            'firstDay' => 1,
            'locale' => 'pt-br',
            'initialView' => 'dayGridMonth',

            // ✅ criar selecionando dias
            'selectable' => true,
            'selectMirror' => true,
            'unselectAuto' => true,

            // ✅ sem arrastar/editar eventos
            'editable' => false,
        ];
    }

    /**
     * ✅ Clique em um dia (1 dia) -> abre Create
     */
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

    /**
     * ✅ Seleção arrastando vários dias -> abre Create
     * (end é exclusivo no FullCalendar)
     */
    public function select(): string
    {
        return <<<JS
            function(info) {
                info.view.calendar.el.__livewire.mountAction('create', {
                    start: info.startStr,
                    end: info.endStr
                });
            }
        JS;
    }

    /**
     * 🚫 Bloqueia clique em evento (impede abrir modal do evento e excluir por ali)
     */
    public function eventClick(): string
    {
        return <<<JS
            function(info) {
                info.jsEvent.preventDefault();
                return false;
            }
        JS;
    }

    /**
     * ✅ Não expõe ações no modal do evento (segurança extra)
     */
    protected function modalActions(): array
    {
        return [];
    }

    public function getFormSchema(): array
    {
        return [
            Hidden::make('user_id')
                ->default(fn () => auth()->id()),

            // admin pode escolher usuário; usuário comum fica travado no logado
            Select::make('user_id_select')
                ->label('Usuário')
                ->default(fn () => auth()->id())
                ->required()
                ->disabled(fn () => ! $this->isAdmin())
                ->dehydrated(false)
                ->options(function () {
                    if ($this->isAdmin()) {
                        return User::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    }

                    $u = auth()->user();
                    return $u ? [$u->id => $u->name] : [];
                })
                ->searchable(fn () => $this->isAdmin())
                ->preload(fn () => $this->isAdmin())
                ->live()
                ->afterStateUpdated(function (?int $state, Set $set) {
                    if ($this->isAdmin() && $state) {
                        $set('user_id', $state);
                    }
                }),

            DatePicker::make('inicio')
                ->label('Primeiro dia')
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                    $qtd = (int) ($get('quantidade_dias') ?? 0);
                    $set('fim_preview', $this->calcularFim($state, $qtd));
                }),

            TextInput::make('quantidade_dias')
                ->label('Quantidade de dias')
                ->numeric()
                ->minValue(1)
                ->maxValue(30)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                    $inicio = $get('inicio');
                    $qtd = (int) $state;
                    $set('fim_preview', $this->calcularFim($inicio, $qtd));
                })
                ->helperText('O fim é calculado automaticamente (contagem inclusiva).'),

            Hidden::make('fim_preview'),

            Placeholder::make('preview')
                ->label('')
                ->content(function (Get $get): string {
                    $inicio = $get('inicio');
                    $qtd = (int) ($get('quantidade_dias') ?? 0);
                    $fim = $get('fim_preview');

                    if (! $inicio || $qtd < 1) return 'Selecione o primeiro dia e a quantidade de dias.';
                    if (! $fim) return 'Não foi possível calcular a data final.';

                    return '📌 Período: ' .
                        Carbon::parse($inicio)->format('d/m/Y') .
                        ' até ' .
                        Carbon::parse($fim)->format('d/m/Y') .
                        " ({$qtd} dia(s)).";
                }),
        ];
    }

    protected function headerActions(): array
    {
        return [
            Actions\CreateAction::make('create')
                ->label('Registrar férias')
                ->mountUsing(function (Schema $form, array $arguments) {
                    $startStr = $arguments['start'] ?? null;
                    $endStr = $arguments['end'] ?? null;

                    $inicio = $startStr ? Carbon::parse($startStr)->toDateString() : null;

                    $quantidade = 1;
                    if ($startStr && $endStr) {
                        $start = Carbon::parse($startStr)->startOfDay();
                        $end = Carbon::parse($endStr)->startOfDay();

                        if ($end->gt($start)) {
                            $fimInclusive = $end->copy()->subDay();
                            $quantidade = $start->diffInDays($fimInclusive) + 1;
                        }
                    }

                    $currentUserId = auth()->id();

                    $form->fill([
                        'user_id' => $currentUserId,
                        'user_id_select' => $currentUserId,
                        'inicio' => $inicio,
                        'quantidade_dias' => $quantidade,
                        'fim_preview' => $inicio ? $this->calcularFim($inicio, $quantidade) : null,
                    ]);
                })
                ->action(function (array $data): void {
                    try {
                        // ✅ usuário comum sempre cria para si
                        if (! $this->isAdmin()) {
                            $data['user_id'] = auth()->id();
                        }

                        app(FeriasService::class)->criar($data);

                        Notification::make()
                            ->title('Férias registradas')
                            ->success()
                            ->send();

                        $this->refreshRecords();
                        $this->dispatch('$refresh');
                        $this->dispatch('feriasUpdated');
                    } catch (ValidationException $e) {
                        $msg = collect($e->errors())->flatten()->first()
                            ?: 'Não foi possível registrar as férias.';

                        Notification::make()
                            ->title('Não é possível registrar férias')
                            ->body($msg)
                            ->danger()
                            ->send();

                        throw $e;
                    }
                }),
        ];
    }

    public function fetchEvents(array $fetchInfo): array
    {
        $rangeStart = Carbon::parse($fetchInfo['start'])->startOfDay();
        $rangeEnd = Carbon::parse($fetchInfo['end'])->endOfDay();

        $ferias = Ferias::query()
            ->with('user')
            ->whereDate('inicio', '<=', $rangeEnd->toDateString())
            ->whereDate('fim', '>=', $rangeStart->toDateString())
            ->get();

        return $ferias->map(function (Ferias $f) {
            $user = $f->user;
            $title = $user?->name ?? 'Usuário';

            $color = $this->corPorRole($user);

            // end é exclusivo no FullCalendar
            $endExclusive = Carbon::parse($f->fim)->addDay()->toDateString();

            return [
                'id' => (string) $f->id,
                'title' => $title,
                'start' => Carbon::parse($f->inicio)->toDateString(),
                'end' => $endExclusive,
                'allDay' => true,
                'backgroundColor' => $color,
                'borderColor' => $color,
            ];
        })->all();
    }
}
