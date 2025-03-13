<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes
Route::group(['middleware' => ['auth:sanctum']], function () {

    // API v1
    Route::group(['prefix' => 'v1'], function () {

        // Get the authenticated user
        Route::group(['prefix' => 'user'], function () {
            Route::get('whoami', [\App\Http\Controllers\ApiController::class, 'user']);
        });

        // Sync endpoints
        Route::group(['prefix' => 'sync'], function () {
            Route::post('playlist/{playlist}/{force?}', [\App\Http\Controllers\ApiController::class, 'refreshPlaylist']);
            Route::post('epg/{epg}/{force?}', [\App\Http\Controllers\ApiController::class, 'refreshEpg']);
        });

        // ...

    });

    // ...
});
