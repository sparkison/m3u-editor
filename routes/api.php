<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV url
Route::group(['prefix' => 'stream'], function () {
    // Stream an IPTV channel (MPEGTS/MP4)
    Route::get('{encodedId}.ts', \App\Http\Controllers\ChannelTsStreamController::class)
        ->where('encodedId', '[A-Ba-b0-9]\.ts')
        ->name('stream.ts');

    // Stream an IPTV episode (HLS)
    Route::get('e/{encodedId}/playlist.m3u8', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodePlaylist'])
        ->name('stream.hls.episode');

    // Serve segments (catch-all for any .ts file)
    Route::get('e/{episodeId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodeSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.episode.segment');

    // Stream an IPTV channel (HLS)
    Route::get('{encodedId}/playlist.m3u8',[\App\Http\Controllers\HlsStreamController::class, 'serveChannelPlaylist'])
        ->name('stream.hls.playlist');

    // Serve segments (catch-all for any .ts file)
    Route::get('{channelId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveChannelSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.hls.segment');
});
