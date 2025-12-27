<?php

namespace App\Filament\Resources\Bloqueios;

use App\Filament\Resources\Bloqueios\BloqueioResource\Pages\ManageBloqueios;
use App\Models\Bloqueio;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

class BloqueioResource extends Resource
{
    protected static ?string $model = Bloqueio::class;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-no-symbol';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bloqueios';
    }

    public static function getModelLabel(): string
    {
        return 'Bloqueio';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Bloqueios';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Agenda';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('EPC')
                ->required()
                ->searchable()
                ->preload()
                ->options(fn () => User::query()
                    ->role('epc')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()
                ),

            DatePicker::make('dia')
                ->label('Dia')
                ->required()
                ->native(false)
                ->helperText('Somente dias úteis. Sábado e domingo já são bloqueados automaticamente.')
                ->rules([
                    // Fim de semana
                    fn () => function (string $attribute, $value, \Closure $fail): void {
                        if (! $value) return;

                        $date = Carbon::parse($value);
                        if ($date->isWeekend()) {
                            $fail('Fim de semana já é bloqueado automaticamente. Selecione um dia útil.');
                        }
                    },

                    // Unicidade (user_id + dia)
                    fn (Get $get, ?Bloqueio $record) => Rule::unique('bloqueios', 'dia')
                        ->where('user_id', $get('user_id'))
                        ->ignore($record?->getKey()),
                ]),

            TextInput::make('motivo')
                ->label('Motivo (opcional)')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('epc.name')
                    ->label('EPC')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('dia')
                    ->label('Dia')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(60),

                TextColumn::make('criadoPor.name')
                    ->label('Criado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // ✅ Lixeira por linha (Filament v4 usa recordActions + Filament\Actions\DeleteAction)
            ->recordActions([
                DeleteAction::make()
                    ->label('') // só ícone
                    ->icon('heroicon-o-trash')
                    ->tooltip('Excluir bloqueio')
                    ->requiresConfirmation()
                    ->modalHeading('Excluir bloqueio?')
                    ->modalDescription('Isso desbloqueia o dia selecionado.')
                    ->successNotificationTitle('Bloqueio removido'),
            ])
            ->defaultSort('dia', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBloqueios::route('/'),
        ];
    }
}
