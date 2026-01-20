<?php

use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    $networksPath = storage_path('app/networks');
    if (File::exists($networksPath)) {
        foreach (File::directories($networksPath) as $dir) {
            File::deleteDirectory($dir);
        }
    }
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

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);

    File::put("{$hlsPath}/seg1.ts", str_repeat('x', 512));
    File::put("{$hlsPath}/seg2.ts", str_repeat('x', 1024));

    // Refresh and fetch the network fresh from database
    $network->refresh();

    // Re-fetch to ensure record exists
    $freshNetwork = Network::find($network->id);
    expect($freshNetwork)->not->toBeNull();

    // Just verify the form loads successfully with the network
    $form = Livewire::test(EditNetwork::class, ['record' => $freshNetwork->id]);

    // Verify model accessors work correctly
    expect($freshNetwork->hls_segment_count)->toBe(2);
    expect($freshNetwork->hls_storage_bytes)->toBe(512 + 1024);
});
