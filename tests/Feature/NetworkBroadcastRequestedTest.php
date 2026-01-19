<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;

it('does not auto-start broadcast when broadcast_requested is false', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    // Create a programme so the network has content
    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = app(NetworkBroadcastService::class);

    // Run a tick - it should NOT auto-start because broadcast_requested is false
    $result = $service->tick($network);

    expect($result['action'])->toBe('idle');
    expect($network->fresh()->broadcast_pid)->toBeNull();
});

it('auto-starts broadcast when broadcast_requested is true', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    // Create a programme so the network has content
    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldReceive('start')->once()->andReturn(true);

    // Swap in the mock
    app()->instance(NetworkBroadcastService::class, $service);

    $result = $service->tick($network);

    expect($result['action'])->toBe('started');
});

it('sets broadcast_requested to false when stop is called', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => now(),
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
});
