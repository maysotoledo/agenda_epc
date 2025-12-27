<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CalendarWidget;
use App\Filament\Widgets\SelecionarUsuarioAgendaWidget;
use Filament\Pages\Page;

class AgendaCalendario extends Page
{
    // ✅ Na sua versão do Filament, $view é NÃO-static
    protected string $view = 'filament.pages.agenda-calendario';

    protected static ?string $title = 'Agenda';
    protected static ?string $navigationLabel = 'Agenda';

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-calendar-days';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Agenda';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    protected static ?string $slug = 'agenda-calendario';

    /**
     * ✅ Widgets que aparecem no topo da página
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SelecionarUsuarioAgendaWidget::class,
            CalendarWidget::class,
        ];
    }
}
