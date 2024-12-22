<?php

namespace App\Providers;

use App\Events\PlaylistCreated;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schedule;
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
        } catch (\Throwable $e) {
            // Log the error
            report($e);
        }

        // Register schedule
        Schedule::command('app:refresh-playlist')
            ->everyFifteenMinutes();
    }
}
