<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Bloqueio;
use Illuminate\Auth\Access\HandlesAuthorization;

class BloqueioPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bloqueio');
    }

    public function view(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('View:Bloqueio');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bloqueio');
    }

    public function update(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('Update:Bloqueio');
    }

    public function delete(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('Delete:Bloqueio');
    }

    public function restore(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('Restore:Bloqueio');
    }

    public function forceDelete(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('ForceDelete:Bloqueio');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bloqueio');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bloqueio');
    }

    public function replicate(AuthUser $authUser, Bloqueio $bloqueio): bool
    {
        return $authUser->can('Replicate:Bloqueio');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bloqueio');
    }

}