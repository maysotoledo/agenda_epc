<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Evento;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class EventoPolicy
{
    use HandlesAuthorization;

    private function isAdmin(AuthUser $user): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole('super_admin');
    }

    private function getCreatorId(Evento $evento): ?int
    {
        foreach (['created_by', 'created_by_id', 'created_by_user_id', 'creator_id'] as $key) {
            $val = $evento->getAttribute($key);
            if ($val !== null) {
                return (int) $val;
            }
        }

        return null;
    }

    private function isCreator(AuthUser $user, Evento $evento): bool
    {
        $creatorId = $this->getCreatorId($evento);

        return $creatorId !== null && (int) $user->getAuthIdentifier() === $creatorId;
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return true;
    }

    public function view(AuthUser $authUser, Evento $evento): bool
    {
        return true;
    }

    public function create(AuthUser $authUser): bool
    {
        return true;
    }

    // ✅ regra pedida
    public function update(AuthUser $authUser, Evento $evento): bool
    {
        return $this->isAdmin($authUser) || $this->isCreator($authUser, $evento);
    }

    // ✅ regra pedida
    public function delete(AuthUser $authUser, Evento $evento): bool
    {
        return $this->isAdmin($authUser) || $this->isCreator($authUser, $evento);
    }

    public function restore(AuthUser $authUser, Evento $evento): bool
    {
        return $this->isAdmin($authUser);
    }

    public function forceDelete(AuthUser $authUser, Evento $evento): bool
    {
        return $this->isAdmin($authUser);
    }
}
