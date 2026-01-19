<?php

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\mock;

const M3U_HEADER = "#EXTM3U\n";

beforeEach(function () {
    // Ensure storage area is clean
    File::deleteDirectory(storage_path('app/networks'));
});

it('calls NetworkBroadcastService::stop when a network is deleted', function () {
    $network = Network::factory()->activeBroadcast()->create();

    // Mock the service and expect stop() to be called with the network
    mock(NetworkBroadcastService::class)
        ->shouldReceive('stop')
        ->once()
        ->withArgs(function ($arg) use ($network) {
            return $arg->id === $network->id;
        })
        ->andReturnTrue();

    $network->delete();

    $this->assertDatabaseMissing('networks', ['id' => $network->id]);
});

it('removes HLS storage directory when network is deleted', function () {
    $network = Network::factory()->create();

    $hlsPath = $network->getHlsStoragePath();

    File::ensureDirectoryExists($hlsPath);
    File::put("{$hlsPath}/live.m3u8", M3U_HEADER);
    File::put("{$hlsPath}/live000001.ts", 'segment');

    expect(File::isDirectory($hlsPath))->toBeTrue();

    $network->delete();

    expect(File::isDirectory($hlsPath))->toBeFalse();
    $this->assertDatabaseMissing('networks', ['id' => $network->id]);
});

it('stop removes lingering HLS files even when pid is null', function () {
    $network = Network::factory()->activeBroadcast()->create();

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);
    File::put("{$hlsPath}/live.m3u8", M3U_HEADER);
    File::put("{$hlsPath}/live000001.ts", 'segment');

    // Simulate broadcast already stopped (no pid)
    $network->update(['broadcast_pid' => null, 'broadcast_started_at' => null]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    expect(File::exists("{$hlsPath}/live.m3u8"))->toBeFalse();
    expect(File::exists("{$hlsPath}/live000001.ts"))->toBeFalse();
});

it('stop removes lingering HLS files when pid exists but process not running', function () {
    $network = Network::factory()->activeBroadcast()->create();

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);
    File::put("{$hlsPath}/live.m3u8", M3U_HEADER);
    File::put("{$hlsPath}/live000001.ts", 'segment');

    // Simulate PID set but process not running
    $network->update(['broadcast_pid' => 999999, 'broadcast_started_at' => now()]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    expect(File::exists("{$hlsPath}/live.m3u8"))->toBeFalse();
    expect(File::exists("{$hlsPath}/live000001.ts"))->toBeFalse();
});

it('deleted network endpoints return 404', function () {
    $network = Network::factory()->activeBroadcast()->create();
    $hlsPath = $network->getHlsStoragePath();

    File::ensureDirectoryExists($hlsPath);
    File::put("{$hlsPath}/live.m3u8", M3U_HEADER);

    $uuid = $network->uuid;

    $network->delete();

    // Playlist and stream endpoints should now return 404
    $playlistResponse = $this->get(route('network.hls.playlist', ['network' => $uuid]));
    $streamResponse = $this->get(url("/network/{$uuid}/stream.ts"));

    $playlistResponse->assertNotFound();
    $streamResponse->assertNotFound();
});

it('cleanupSegments removes old .ts segments', function () {
    $network = Network::factory()->create();
    $hlsPath = $network->getHlsStoragePath();

    File::ensureDirectoryExists($hlsPath);
    $old = "{$hlsPath}/old001.ts";
    $new = "{$hlsPath}/new001.ts";

    File::put($old, 'old');
    File::put($new, 'new');

    // Set old file to be older than threshold (3 minutes ago)
    touch($old, time() - 180);

    $service = app(NetworkBroadcastService::class);
    $deleted = $service->cleanupSegments($network);

    expect($deleted)->toBe(1);
    expect(File::exists($old))->toBeFalse();
    expect(File::exists($new))->toBeTrue();
});

it('stops broadcast and clears schedule when last content is removed', function () {
    $network = Network::factory()->activeBroadcast()->create();
    $channel = \App\Models\Channel::factory()->create();

    // Add content to the network
    $networkContent = \App\Models\NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($channel),
        'contentable_id' => $channel->id,
        'sort_order' => 1,
    ]);

    // Create some programmes
    \App\Models\NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => now(),
        'end_time' => now()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($channel),
        'contentable_id' => $channel->id,
    ]);

    expect($network->programmes()->count())->toBe(1);

    // Mock the services
    mock(\App\Services\NetworkBroadcastService::class)
        ->shouldReceive('stop')
        ->once()
        ->withArgs(fn ($arg) => $arg->id === $network->id);

    mock(\App\Services\NetworkEpgService::class)
        ->shouldReceive('generateEpg')
        ->once()
        ->withArgs(fn ($arg) => $arg->id === $network->id);

    // Delete the content
    $networkContent->delete();

    // Verify cleanup happened
    $network->refresh();
    expect($network->programmes()->count())->toBe(0);
    expect($network->schedule_generated_at)->toBeNull();
});
