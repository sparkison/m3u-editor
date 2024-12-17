<?php

namespace App\Providers;

use App\Events\PlaylistCreated;
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
            // Playlist model might not exist yet
            Playlist::created(fn(Playlist $playlist) => event(new PlaylistCreated($playlist)));
        } catch (\Throwable $e) {
            // Log the error
            report($e);
        }
    }
}
