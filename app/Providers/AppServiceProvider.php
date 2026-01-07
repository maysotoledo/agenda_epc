<?php

namespace App\Providers;

use App\Models\Bloqueio;
use App\Models\Evento;
use App\Models\Ferias;
use App\Observers\EventoObserver;
use App\Policies\BloqueioPolicy;
use App\Policies\EventoPolicy;
use App\Policies\FeriasPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Auth\User;
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
            Gate::policy(Ferias::class, FeriasPolicy::class);
            Gate::policy(User::class, UserPolicy::class);
            Gate::policy(Bloqueio::class, BloqueioPolicy::class);

    }
}
