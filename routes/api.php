<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// MediaFlow-style proxy routes
Route::group(['prefix' => 'mediaflow/proxy', 'middleware' => [\App\Http\Middleware\MediaFlowProxyMiddleware::class]], function () {
    // HLS manifest proxy (MediaFlow compatible)
    Route::get('hls/manifest.m3u8', [\App\Http\Controllers\MediaFlowProxyController::class, 'proxyHlsManifest'])
        ->name('mediaflow.proxy.hls.manifest');
    
    // Generic stream proxy (MediaFlow compatible)
    Route::match(['GET', 'HEAD'], 'stream', [\App\Http\Controllers\MediaFlowProxyController::class, 'proxyStream'])
        ->name('mediaflow.proxy.stream');
    Route::match(['GET', 'HEAD'], 'stream/{filename}', [\App\Http\Controllers\MediaFlowProxyController::class, 'proxyStream'])
        ->name('mediaflow.proxy.stream.file')
        ->where('filename', '.*');
    
    // Channel/Episode specific proxying with failover support
    Route::get('channel/{id}/stream', [\App\Http\Controllers\MediaFlowProxyController::class, 'proxyChannelStream'])
        ->name('mediaflow.proxy.channel');
    Route::get('episode/{id}/stream', [\App\Http\Controllers\MediaFlowProxyController::class, 'proxyEpisodeStream'])
        ->name('mediaflow.proxy.episode');
    
    // Utility endpoints
    Route::get('ip', [\App\Http\Controllers\MediaFlowProxyController::class, 'getPublicIp'])
        ->name('mediaflow.proxy.ip');
    Route::get('health', [\App\Http\Controllers\MediaFlowProxyController::class, 'healthCheck'])
        ->name('mediaflow.proxy.health');
});

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
