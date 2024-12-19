<?php

use App\Http\Controllers\PlaylistGenerateController;
use Illuminate\Support\Facades\Route;

// Generate M3U playlist from the playlist configuration
Route::get('/playlists/{playlist}/generate', PlaylistGenerateController::class)
    ->name('playlists.generate');
