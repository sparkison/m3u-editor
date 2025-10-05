<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV url
Route::group(['prefix' => 'stream'], function () {
    // Test stream endpoint (TS only)
    Route::get('test/{timeout}.ts', [\App\Http\Controllers\StreamTestController::class, 'testStream'])
        ->where('timeout', '[0-9]+')
        ->name('stream.test');
});

// Shared streaming API routes (xTeVe-like proxy functionality)
Route::group(['prefix' => 'shared'], function () {
    // HLS segments for shared streams
    Route::get('hls/{type}/{encodedId}/{segment}', [\App\Http\Controllers\SharedStreamController::class, 'serveHLSSegment'])
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

// Enhanced API routes for dashboard and monitoring
Route::group(['prefix' => 'monitor'], function () {
    // Core streaming statistics
    Route::get('stats', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getStats'])
        ->name('api.monitor.stats');

    // Real-time metrics for dashboard widgets
    Route::get('realtime', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getRealTimeMetrics'])
        ->name('api.monitor.realtime');

    // Dashboard data (comprehensive analytics)
    Route::get('dashboard', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getDashboardData'])
        ->name('api.monitor.dashboard');

    // Performance history
    Route::get('performance', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getPerformanceHistory'])
        ->name('api.monitor.performance');

    // System alerts
    Route::get('alerts', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getAlerts'])
        ->name('api.monitor.alerts');

    // System health check
    Route::get('health', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getHealth'])
        ->name('api.monitor.health');

    // System information (including git info)
    Route::get('system', [\App\Http\Controllers\Api\SystemInfoController::class, 'getSystemInfo'])
        ->name('api.monitor.system');

    // Stream management
    Route::get('streams', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getActiveStreams'])
        ->name('api.monitor.streams');

    Route::post('streams/test', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'testStream'])
        ->name('api.monitor.test');

    Route::delete('streams/{streamId}', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'stopStream'])
        ->name('api.monitor.stop');

    Route::post('cleanup', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'cleanup'])
        ->name('api.monitor.cleanup');
});

/*
 * EPG API routes
 */
Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getData'])
        ->name('api.epg.data');
    Route::get('playlist/{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getDataForPlaylist'])
        ->name('api.epg.playlist.data');
});

/*
 * m3u-proxy API routes
 */
Route::group(['prefix' => 'm3u-proxy'], function () {
    Route::get('channel/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channel'])
        ->name('m3u-proxy.channel');
    Route::get('episode/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episode'])
        ->name('m3u-proxy.episode');
    Route::get('channel/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channelPlayer'])
        ->name('m3u-proxy.channel.player');
    Route::get('episode/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episodePlayer'])
        ->name('m3u-proxy.episode.player');
});
