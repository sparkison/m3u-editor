<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV url
Route::group(['prefix' => 'stream'], function () {
    // Stream an IPTV episode (HLS)
    Route::get('e/{encodedId}/playlist.m3u8', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodePlaylist'])
        ->name('stream.hls.episode');

    // Serve espisode segments (catch-all for any .ts file)
    Route::get('e/{episodeId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodeSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.episode.segment');

    // Stream an IPTV channel (HLS)
    Route::get('{encodedId}/playlist.m3u8',[\App\Http\Controllers\HlsStreamController::class, 'serveChannelPlaylist'])
        ->name('stream.hls.playlist');

    // Serve channel segments (catch-all for any .ts file)
    Route::get('{channelId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveChannelSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.hls.segment');
});

// Shared streaming API routes (xTeVe-like proxy functionality)
Route::group(['prefix' => 'shared'], function () {
    // HLS segments for shared streams
    Route::get('hls/{encodedId}/{segment}', [\App\Http\Controllers\SharedStreamController::class, 'serveHLSSegment'])
        ->where('segment', '.*\.ts')
        ->name('shared.stream.hls.segment');
    
    // Stream statistics and management
    Route::get('stats', [\App\Http\Controllers\SharedStreamController::class, 'getStreamStats'])
        ->name('shared.stream.stats');
    
    Route::delete('stream/{streamKey}', [\App\Http\Controllers\SharedStreamController::class, 'stopStream'])
        ->name('shared.stream.stop');
    
    // Test streaming
    Route::post('test', [\App\Http\Controllers\SharedStreamController::class, 'testStream'])
        ->name('shared.stream.test');
});
