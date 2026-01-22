<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->createdNetworkPaths = [];
});

afterEach(function () {
    // Clean up only the directories created by this test
    foreach ($this->createdNetworkPaths ?? [] as $path) {
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('reconnect after stop cannot resume HLS playlist or segments', function () {
    // 1. Fix the "Time Drift" - ensures now() in test matches now() in Controller
    Carbon::setTestNow(now());

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'enabled' => true,
    ]);

    // Create the HLS storage directory and files at the real storage_path location
    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);
    $this->createdNetworkPaths[] = $hlsPath;

    // Create a playlist and a segment
    File::put("{$hlsPath}/live.m3u8", "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n");
    File::put("{$hlsPath}/live000001.ts", 'segment-data');

    // 3. Simulate broadcast as "running"
    $network->update([
        'broadcast_started_at' => now(),
        'broadcast_pid' => 999999,
    ]);

    // --- SANITY CHECK ---
    // We refresh the model to ensure the ID/UUID and attributes are synced
    $network = $network->fresh();

    $this->get(route('network.hls.playlist', ['network' => $network->uuid]))
        ->assertStatus(200);

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    $segmentResp->assertStatus(200);

    // Cache headers
    $cacheHeader = $segmentResp->headers->get('Cache-Control');
    expect($cacheHeader)->toContain('no-cache');
    expect($cacheHeader)->toContain('no-store');

    // 4. ACTION: Stop the broadcast
    app(\App\Services\NetworkBroadcastService::class)->stop($network);

    // 5. VERIFY
    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    expect(in_array($playlistResp->getStatusCode(), [503, 404]))->toBeTrue();

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    expect(in_array($segmentResp->getStatusCode(), [503, 404]))->toBeTrue();

    // Reset time
    Carbon::setTestNow();
})->group('serial');
