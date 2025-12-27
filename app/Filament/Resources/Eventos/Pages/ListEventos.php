<?php

namespace App\Filament\Resources\Eventos\Pages;

use App\Filament\Resources\Eventos\EventoResource;
use App\Models\Evento;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEventos extends ListRecords
{
    protected static string $resource = EventoResource::class;

    public function getTabs(): array
    {
        return [
            'ativos' => Tab::make('Ativos')
                ->icon('heroicon-o-check-circle')
                ->badge(Evento::query()->count()),

            'cancelados' => Tab::make('Cancelados')
                ->icon('heroicon-o-x-circle')
                ->badge(Evento::onlyTrashed()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed()),

            'todos' => Tab::make('Todos')
                ->icon('heroicon-o-squares-2x2')
                ->badge(Evento::withTrashed()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->withTrashed()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'cancelados';
    }
}
