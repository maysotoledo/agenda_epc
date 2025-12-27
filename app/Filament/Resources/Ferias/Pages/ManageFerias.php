<?php

namespace App\Filament\Resources\Ferias\FeriasResource\Pages;

use App\Filament\Resources\Ferias\FeriasResource;
use App\Filament\Widgets\FeriasCalendarWidget;
use App\Models\Ferias;
use App\Models\User;
use App\Services\FeriasService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ManageFerias extends ManageRecords
{
    protected static string $resource = FeriasResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            FeriasCalendarWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        // botão de criar fica no widget do calendário
        return [];
    }

    #[On('feriasUpdated')]
    public function onFeriasUpdated(): void
    {
        $this->resetTable();
        $this->dispatch('$refresh');
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();
        return (bool) $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    private function canManageOwnOrAdmin(Ferias $record): bool
    {
        $userId = auth()->id();
        if (! $userId) return false;

        return $this->isAdmin() || ((int) $record->user_id === (int) $userId);
    }

    private function calcularFim(?string $inicio, ?int $qtd): ?string
    {
        if (! $inicio || ! $qtd || $qtd < 1) return null;

        $start = Carbon::parse($inicio)->startOfDay();
        return $start->copy()->addDays($qtd - 1)->toDateString();
    }

    private function feriasSchema(): array
    {
        return [
            Hidden::make('user_id')
                ->default(fn () => auth()->id()),

            // admin pode escolher; usuário comum fica travado no próprio usuário
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
                }),

            Hidden::make('fim_preview'),

            Placeholder::make('preview')
                ->label('')
                ->content(function (Get $get): string {
                    $inicio = $get('inicio');
                    $qtd = (int) ($get('quantidade_dias') ?? 0);
                    $fim = $get('fim_preview');

                    if (! $inicio || $qtd < 1) {
                        return 'Selecione o primeiro dia e a quantidade de dias.';
                    }

                    if (! $fim) {
                        return 'Não foi possível calcular a data final.';
                    }

                    return '📌 Período: ' .
                        Carbon::parse($inicio)->format('d/m/Y') .
                        ' até ' .
                        Carbon::parse($fim)->format('d/m/Y') .
                        " ({$qtd} dia(s)).";
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Ferias::query()->with('user')->orderByDesc('inicio'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('inicio')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fim')
                    ->label('Fim')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('dias')
                    ->label('Dias')
                    ->state(fn (Ferias $record) => $record->dias)
                    ->sortable(),

                TextColumn::make('ano')
                    ->label('Ano')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('ano')
                    ->label('Ano')
                    ->options(fn () => Ferias::query()
                        ->select('ano')
                        ->distinct()
                        ->orderByDesc('ano')
                        ->pluck('ano', 'ano')
                        ->all()
                    ),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Ferias $record) => $this->canManageOwnOrAdmin($record))
                    ->schema($this->feriasSchema())
                    ->fillForm(function (Ferias $record): array {
                        $inicio = $record->inicio?->toDateString();
                        $qtd = $record->dias;

                        return [
                            'user_id' => $record->user_id,
                            'user_id_select' => $record->user_id,
                            'inicio' => $inicio,
                            'quantidade_dias' => $qtd,
                            'fim_preview' => $inicio ? $this->calcularFim($inicio, $qtd) : null,
                        ];
                    })
                    ->action(function (array $data, Ferias $record): void {
                        try {
                            app(FeriasService::class)->editar($record, $data);

                            Notification::make()
                                ->title('Férias atualizadas')
                                ->success()
                                ->send();

                            $this->dispatch('feriasUpdated');
                        } catch (ValidationException $e) {
                            $msg = collect($e->errors())->flatten()->first()
                                ?: 'Não foi possível atualizar as férias.';

                            Notification::make()
                                ->title('Não é possível atualizar')
                                ->body($msg)
                                ->danger()
                                ->send();

                            throw $e;
                        }
                    }),

                DeleteAction::make()
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->visible(fn (Ferias $record) => $this->canManageOwnOrAdmin($record))
                    ->requiresConfirmation()
                    ->modalHeading('Excluir férias?')
                    ->modalDescription('Isso removerá esse período do calendário.')
                    ->after(fn () => $this->dispatch('feriasUpdated')),
            ]);
    }
}
