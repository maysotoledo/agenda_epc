<?php

namespace App\Providers;

use App\Filament\Widgets\CalendarWidget;
use App\Filament\Widgets\FeriasCalendarWidget;
use App\Filament\Widgets\SelecionarUsuarioAgendaWidget;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class LivewireComponentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component(
            'app.filament.widgets.selecionar-usuario-agenda-widget',
            SelecionarUsuarioAgendaWidget::class
        );

        Livewire::component(
            'app.filament.widgets.calendar-widget',
            CalendarWidget::class
        );

        // ✅ REGISTRA O WIDGET DE FÉRIAS (resolve o erro do componente não encontrado)
        Livewire::component(
            'app.filament.widgets.ferias-calendar-widget',
            FeriasCalendarWidget::class
        );
    }
}
