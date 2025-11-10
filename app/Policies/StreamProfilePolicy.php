<?php

namespace App\Policies;

use App\Models\StreamProfile;
use App\Models\User;

class StreamProfilePolicy
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
    public function view(User $user, StreamProfile $streamProfile): bool
    {
        // StreamProfile doesn't have user_id based on the model structure
        // All users can view all stream profiles
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }
}
