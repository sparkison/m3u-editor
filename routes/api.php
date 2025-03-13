<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes
Route::group(['middleware' => ['auth:sanctum']], function () {

    // API v1
    Route::group(['prefix' => 'v1'], function () {

        // Get the authenticated user
        Route::get('/whoami', [\App\Http\Controllers\ApiController::class, 'user']);

        // Sync endpoints
        Route::post('/sync-playlist/{playlist}/{force?}', [\App\Http\Controllers\ApiController::class, 'refreshPlaylist']);
        Route::post('/sync-epg/{epg}/{force?}', [\App\Http\Controllers\ApiController::class, 'refreshEpg']);

        // ...

    });

    // ...
});
