<?php

namespace App\Filament\Resources\Bloqueios;

use App\Filament\Resources\Bloqueios\BloqueioResource\Pages\ManageBloqueios;
use App\Models\Bloqueio;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
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

    // ✅ Tipagem correta (Filament v4)
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationLabel = 'Bloqueios';
    protected static ?string $modelLabel = 'Bloqueio';
    protected static ?string $pluralModelLabel = 'Bloqueios';

    // ✅ Tipagem correta (Filament v4)
    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

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
                    // ✅ Regra de fim de semana:
                    // IMPORTANTE: o Filament avalia esta closure, então ela deve RETORNAR a regra.
                    fn () => function (string $attribute, $value, \Closure $fail): void {
                        if (! $value) {
                            return;
                        }

                        $date = Carbon::parse($value);

                        if ($date->isWeekend()) {
                            $fail('Fim de semana já é bloqueado automaticamente. Selecione um dia útil.');
                        }
                    },

                    // ✅ Regra de unicidade: (user_id + dia)
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
            ->defaultSort('dia', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBloqueios::route('/'),
        ];
    }
}
