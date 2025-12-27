<?php

namespace App\Filament\Resources\Ferias;

use App\Filament\Resources\Ferias\FeriasResource\Pages\ManageFerias;
use App\Models\Ferias;
use BackedEnum;
use Filament\Resources\Resource;
use UnitEnum;

class FeriasResource extends Resource
{
    protected static ?string $model = Ferias::class;

    public static function getNavigationLabel(): string
    {
        return 'Férias';
    }

    public static function getModelLabel(): string
    {
        return 'Férias';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Férias';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-sun';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Férias';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFerias::route('/'),
        ];
    }
}
