<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasUserFiltering
{
    /**
     * Get the Eloquent query for the resource.
     * Filters by user_id for non-admin users, while admins see all records.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // If user is not an admin, filter by user_id
        if (auth()->check() && !auth()->user()->isAdmin()) {
            $query->where(static::getModel()::make()->getTable() . '.user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Get the global search Eloquent query for the resource.
     * Filters by user_id for non-admin users, while admins see all records.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery();

        // If user is not an admin, filter by user_id
        if (auth()->check() && !auth()->user()->isAdmin()) {
            $query->where(static::getModel()::make()->getTable() . '.user_id', auth()->id());
        }

        return $query;
    }
}
