<?php

namespace App\Filament\Resources\Agendas;

use App\Filament\Resources\Agendas\Pages\AgendaCalendar;
use App\Models\Evento;
use BackedEnum;
use Filament\Resources\Resource;
use UnitEnum;

class AgendaResource extends Resource
{
    protected static ?string $model = Evento::class;

    protected static ?string $navigationLabel = 'Agenda';

    // Filament v4: tipagem correta
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    // Filament v4: tipagem correta
    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    public static function getPages(): array
    {
        return [
            'index' => AgendaCalendar::route('/'),
        ];
    }
}
