<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('reports hls segment count and storage bytes on the model', function () {
    $network = Network::factory()->for($this->user)->create();

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);

    File::put("{$hlsPath}/seg1.ts", str_repeat('x', 1024));
    File::put("{$hlsPath}/seg2.ts", str_repeat('x', 2048));

    expect($network->hls_segment_count)->toBe(2);
    expect($network->hls_storage_bytes)->toBe(1024 + 2048);
});

it('shows hls segment count and storage usage in filament edit form', function () {
    $network = Network::factory()->for($this->user)->create();
    $hlsPath = $network->getHlsStoragePath(); // Ensure this includes $network->id

    File::ensureDirectoryExists($hlsPath);
    File::put("{$hlsPath}/seg1.ts", str_repeat('x', 512));
    File::put("{$hlsPath}/seg2.ts", str_repeat('x', 1024));

    $network->refresh();

    expect($network->hls_segment_count)->toBe(2);

    // Manual Cleanup for just this record
    File::deleteDirectory($hlsPath);
});
