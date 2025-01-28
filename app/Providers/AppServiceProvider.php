<?php

namespace App\Providers;

use App\Events\EpgCreated;
use App\Events\PlaylistCreated;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Disable mass assignment protection (security handled by Filament)
        Model::unguard();

        // Register the event listener
        try {
            // Process playlist on creation
            Playlist::created(fn(Playlist $playlist) => event(new PlaylistCreated($playlist)));
            Playlist::creating(function (Playlist $playlist) {
                $playlist->user_id = auth()->id();
                $playlist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $playlist;
            });

            // Process epg on creation
            Epg::created(fn(Epg $epg) => event(new EpgCreated($epg)));
            Epg::creating(function (Epg $epg) {
                $epg->user_id = auth()->id();
                return $epg;
            });

            // Merged playlist
            // MergedPlaylist::created(fn(MergedPlaylist $mergedPlaylist) => /* ... */);
            MergedPlaylist::creating(function (MergedPlaylist $mergedPlaylist) {
                $mergedPlaylist->user_id = auth()->id();
                $mergedPlaylist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $mergedPlaylist;
            });

            // Custom playlist
            // CustomPlaylist::created(fn(CustomPlaylist $customPlaylist) => /* ... */);
            CustomPlaylist::creating(function (CustomPlaylist $customPlaylist) {
                $customPlaylist->user_id = auth()->id();
                $customPlaylist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $customPlaylist;
            });
        } catch (\Throwable $e) {
            // Log the error
            report($e);
        }
    }
}
