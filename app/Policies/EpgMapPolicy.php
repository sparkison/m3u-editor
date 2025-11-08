<?php

namespace App\Policies;

use App\Models\EpgMap;
use App\Models\User;

class EpgMapPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EpgMap $epgMap): bool
    {
        return $user->isAdmin() || $user->id === $epgMap->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EpgMap $epgMap): bool
    {
        return $user->isAdmin() || $user->id === $epgMap->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EpgMap $epgMap): bool
    {
        return $user->isAdmin() || $user->id === $epgMap->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EpgMap $epgMap): bool
    {
        return $user->isAdmin() || $user->id === $epgMap->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EpgMap $epgMap): bool
    {
        return $user->isAdmin() || $user->id === $epgMap->user_id;
    }
}
