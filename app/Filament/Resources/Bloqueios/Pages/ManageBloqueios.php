<?php

namespace App\Filament\Resources\Bloqueios\BloqueioResource\Pages;

use App\Filament\Resources\Bloqueios\BloqueioResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBloqueios extends ManageRecords
{
    protected static string $resource = BloqueioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getRecordActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
