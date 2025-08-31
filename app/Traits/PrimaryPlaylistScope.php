<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait PrimaryPlaylistScope
{
    /**
     * Restrict queries to models belonging to top-level playlists.
     */
    protected static function bootPrimaryPlaylistScope(): void
    {
        static::addGlobalScope('primary_playlist', function (Builder $builder) {
            $builder->whereHas('playlist', function (Builder $query) {
                $query->whereNull('parent_playlist_id');
            });
        });
    }

    /**
     * Allow querying records for all playlists, including synced children.
     */
    public function scopeWithAllPlaylists(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope('primary_playlist');
    }
}
