<?php

namespace App\Filament\Resources\Agendas\Pages;

use App\Filament\Resources\Agendas\AgendaResource;
use App\Filament\Widgets\CalendarWidget;
use App\Filament\Widgets\SelecionarUsuarioAgendaWidget;
use Filament\Resources\Pages\Page;

class AgendaCalendar extends Page
{
    protected static string $resource = AgendaResource::class;

    // ✅ Filament v4: $view NÃO É static
    protected string $view = 'filament.resources.agendas.pages.agenda-calendar';

    protected function getHeaderWidgets(): array
    {
        return [
            SelecionarUsuarioAgendaWidget::class,
            CalendarWidget::class,
        ];
    }
}
