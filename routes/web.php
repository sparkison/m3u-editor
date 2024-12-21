<?php

use App\Http\Controllers\PlaylistGenerateController;
use Illuminate\Support\Facades\Route;

// Generate M3U playlist from the playlist configuration
Route::get('/playlists/{uuid}/generate', PlaylistGenerateController::class)
    ->name('playlists.generate');

// If local env, show PHP info screen
Route::get('/phpinfo', function () {
    if (app()->environment('local')) {
        phpinfo();
    } else {
        abort(404);
    }
});
