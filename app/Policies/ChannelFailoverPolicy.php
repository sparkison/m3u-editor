<?php

namespace App\Policies;

use App\Models\ChannelFailover;
use App\Models\User;

class ChannelFailoverPolicy
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
    public function view(User $user, ChannelFailover $channelFailover): bool
    {
        return $user->isAdmin() || $user->id === $channelFailover->user_id;
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
    public function update(User $user, ChannelFailover $channelFailover): bool
    {
        return $user->isAdmin() || $user->id === $channelFailover->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ChannelFailover $channelFailover): bool
    {
        return $user->isAdmin() || $user->id === $channelFailover->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ChannelFailover $channelFailover): bool
    {
        return $user->isAdmin() || $user->id === $channelFailover->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ChannelFailover $channelFailover): bool
    {
        return $user->isAdmin() || $user->id === $channelFailover->user_id;
    }
}
