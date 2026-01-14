<?php

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\File;
use function Pest\Laravel\mock;

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
    File::put("{$hlsPath}/live.m3u8", "#EXTM3U\n");
    File::put("{$hlsPath}/live000001.ts", "segment");

    expect(File::isDirectory($hlsPath))->toBeTrue();

    $network->delete();

    expect(File::isDirectory($hlsPath))->toBeFalse();
    $this->assertDatabaseMissing('networks', ['id' => $network->id]);
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
