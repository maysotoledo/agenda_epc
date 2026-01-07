<?php

namespace App\Filament\Resources\Eventos;

use App\Filament\Resources\Eventos\Pages\ListEventos;
use App\Models\Evento;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class EventoResource extends Resource
{
    protected static ?string $model = Evento::class;

    /**
     * ✅ Evita erros de tipagem do PHP/Filament:
     * não redeclare $navigationIcon / $navigationGroup como propriedades.
     * Use métodos.
     */
    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-calendar-days';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Agenda';
    }

    public static function getNavigationLabel(): string
    {
        return 'Eventos Agenda';
    }

    public static function getModelLabel(): string
    {
        return 'Evento Agenda';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Eventos da Agenda';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventos::route('/'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('criadoPor.name')
                    ->label('Criado por'),

                TextColumn::make('user.name')
                    ->label('EPC')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('intimado')
                        ->label('Intimado')
                        ->searchable()
                        ->wrap(),

                TextColumn::make('starts_at')
                    ->label('Comparecimento')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Fim')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('numero_procedimento')
                    ->label('Procedimento')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->label('Cancelado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('canceladoPor.name')
                    ->label('Cancelado por'),
                    //->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('atualizadoPor.name')
                    ->label('Atualizado por'),
                    //->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('EPC')
                    ->options(fn () => User::query()
                        ->role('epc')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    )
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar agendamento?')
                    ->modalDescription('O cancelamento preserva o histórico e pode ser restaurado.')
                    ->visible(fn (Evento $record) => ! $record->trashed())
                    ->action(function (Evento $record): void {
                        $userId = Auth::id();

                        $record->forceFill([
                            'updated_by' => $userId,
                            'deleted_by' => $userId,
                        ])->save();

                        $record->delete(); // soft delete
                    }),

                RestoreAction::make()
                    ->label('Restaurar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (Evento $record) => $record->trashed())
                    ->after(function (Evento $record): void {
                        $record->forceFill([
                            'deleted_by' => null,
                            'updated_by' => Auth::id(),
                        ])->save();
                    }),

                ForceDeleteAction::make()
                    ->label('Excluir definitivo')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Evento $record) => $record->trashed()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('cancelar_selecionados')
                    ->label('Cancelar selecionados')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar agendamentos selecionados?')
                    ->modalDescription('O cancelamento preserva o histórico e pode ser restaurado.')
                    ->action(function (Collection $records): void {
                        $userId = Auth::id();

                        foreach ($records as $record) {
                            /** @var \App\Models\Evento $record */
                            if ($record->trashed()) {
                                continue;
                            }

                            $record->forceFill([
                                'updated_by' => $userId,
                                'deleted_by' => $userId,
                            ])->save();

                            $record->delete(); // soft delete
                        }
                    }),
                BulkAction::make('excluir_definitivo_selecionados')
                    ->label('Excluir definitivo selecionados')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Excluir definitivamente os agendamentos selecionados?')
                    ->modalDescription('⚠️ Esta ação é irreversível.')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            /** @var \App\Models\Evento $record */
                            // Só exclui definitivamente os que já estão cancelados (soft deleted)
                            if (! $record->trashed()) {
                                continue;
                            }

                            $record->forceDelete();
                        }
                    }),

                    BulkAction::make('restaurar_selecionados')
                        ->label('Restaurar selecionados')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $userId = Auth::id();

                            foreach ($records as $record) {
                                /** @var \App\Models\Evento $record */
                                if (! $record->trashed()) {
                                    continue;
                                }

                                $record->restore();

                                $record->forceFill([
                                    'deleted_by' => null,
                                    'updated_by' => $userId,
                                ])->save();
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('Nenhum evento encontrado');
    }
}
