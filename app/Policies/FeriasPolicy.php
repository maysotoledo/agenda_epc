<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Ferias;
use Illuminate\Auth\Access\HandlesAuthorization;

class FeriasPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Ferias');
    }

    public function view(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('View:Ferias');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Ferias');
    }

    public function update(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('Update:Ferias');
    }

    public function delete(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('Delete:Ferias');
    }

    public function restore(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('Restore:Ferias');
    }

    public function forceDelete(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('ForceDelete:Ferias');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Ferias');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Ferias');
    }

    public function replicate(AuthUser $authUser, Ferias $ferias): bool
    {
        return $authUser->can('Replicate:Ferias');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Ferias');
    }

}