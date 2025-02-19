<?php

namespace App\Providers;

use App\Events\EpgCreated;
use App\Events\PlaylistCreated;
use App\Jobs\ReloadApp;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelSettings\Events\SettingsSaved;

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

        // Listen for settings update
        Event::listen(SettingsSaved::class, function ($event) {
            if ($event->settings::class === GeneralSettings::class) {
                // Reload the app so the new settings are applied
                app('Illuminate\Contracts\Bus\Dispatcher')
                    ->dispatch(new ReloadApp());
            }
        });

        // Register the event listener
        try {
            // Process playlist on creation
            Playlist::created(fn(Playlist $playlist) => event(new PlaylistCreated($playlist)));
            Playlist::creating(function (Playlist $playlist) {
                $playlist->user_id = auth()->id();
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                $playlist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $playlist;
            });
            Playlist::updating(function (Playlist $playlist) {
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                return $playlist;
            });

            // Process epg on creation
            Epg::created(fn(Epg $epg) => event(new EpgCreated($epg)));
            Epg::creating(function (Epg $epg) {
                $epg->user_id = auth()->id();
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
                $epg->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $epg;
            });
            Epg::updating(function (Epg $epg) {
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
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

            // Groups
            Group::updated(function (Group $group) {
                $changes = $group->getChanges();
                if (isset($changes['name'])) {
                    // Update the name of the group in the channels
                    $group->channels()
                        ->update(['group' => $group->name]);
                }
            });
        } catch (\Throwable $e) {
            // Log the error
            report($e);
        }
    }
}
