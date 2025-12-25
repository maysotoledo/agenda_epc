<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Evento;
use Illuminate\Auth\Access\HandlesAuthorization;

class EventoPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Evento');
    }

    public function view(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('View:Evento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Evento');
    }

    public function update(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('Update:Evento');
    }

    public function delete(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('Delete:Evento');
    }

    public function restore(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('Restore:Evento');
    }

    public function forceDelete(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('ForceDelete:Evento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Evento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Evento');
    }

    public function replicate(AuthUser $authUser, Evento $evento): bool
    {
        return $authUser->can('Replicate:Evento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Evento');
    }

}