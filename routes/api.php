<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV channel
Route::group(['prefix' => 'stream'], function () {
    // Stream an IPTV channel (MPEGTS/MP4)
    Route::get('{encodedId}.ts', \App\Http\Controllers\ChannelTsStreamController::class)
        ->where('encodedId', '[A-Ba-b0-9]\.ts')
        ->name('stream.ts');

    // Stream an IPTV channel (HLS)
    Route::get('{encodedId}/playlist.m3u8', \App\Http\Controllers\ChannelHlsStreamController::class)
        ->name('stream.hls.playlist');

    // Serve segments (catch-all for any .ts file)
    Route::get('{channelId}/{segment}', [\App\Http\Controllers\ChannelHlsStreamController::class, 'serveSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.hls.segment');
});
