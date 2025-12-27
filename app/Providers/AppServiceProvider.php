<?php

namespace App\Providers;

use App\Models\Evento;
use App\Observers\EventoObserver;
use App\Policies\EventoPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
            Evento::observe(EventoObserver::class);
            Gate::policy(Evento::class, EventoPolicy::class);
    }
}
