<?php

use App\Models\Network;
use Illuminate\Support\Facades\File;

it('reconnect after stop cannot resume HLS playlist or segments', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'enabled' => true,
    ]);

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);

    // Create a playlist and a segment to simulate an active broadcast
    File::put("{$hlsPath}/live.m3u8", "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n");
    File::put("{$hlsPath}/live000001.ts", 'segment-data');

    // Simulate broadcast as "running" by setting started_at and a bogus pid
    $network->update(['broadcast_started_at' => now(), 'broadcast_pid' => 999999]);

    // Sanity: endpoints are reachable while "broadcasting"
    $this->get(route('network.hls.playlist', ['network' => $network->uuid]))->assertStatus(200);

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    $segmentResp->assertStatus(200);

    // Ensure segments are not cacheable by proxies / browsers (allow different ordering/middleware additions)
    $cacheHeader = $segmentResp->headers->get('Cache-Control');
    expect(str_contains($cacheHeader, 'no-cache'))->toBeTrue();
    expect(str_contains($cacheHeader, 'no-store'))->toBeTrue();
    expect(str_contains($cacheHeader, 'max-age=86400'))->toBeFalse();

    // Stop the broadcast (the Filament "Stop Broadcast" action calls this same service)
    app(\App\Services\NetworkBroadcastService::class)->stop($network->refresh());

    // After stopping, reconnecting should NOT be able to resume playback. Allow either 503 (not active) or 404 (files removed).
    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    expect(in_array($playlistResp->getStatusCode(), [503, 404]))->toBeTrue();

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    expect(in_array($segmentResp->getStatusCode(), [503, 404]))->toBeTrue();
});
