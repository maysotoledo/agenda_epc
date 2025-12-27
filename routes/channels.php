<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('agenda.{userId}', function ($user, $userId) {
    // EPC pode ouvir a própria agenda
    if ((int) $user->id === (int) $userId) {
        return true;
    }

    // Admin pode ouvir qualquer agenda (quando estiver visualizando)
    return method_exists($user, 'hasRole') && $user->hasRole('admin');
});
