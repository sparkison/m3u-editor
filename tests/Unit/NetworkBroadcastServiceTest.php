<?php

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);
it('promotes tmp playlist to live when stable', function () {
    // Create a non-persisted Network model with a UUID so we don't need DB/factory
    $network = new Network;
    $network->uuid = (string) Str::uuid();

    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);

    $tmp = "{$hlsPath}/live.m3u8.tmp";
    $live = "{$hlsPath}/live.m3u8";

    File::put($tmp, "#EXTM3U\n#TMP\n");

    $service = app(NetworkBroadcastService::class);

    // Promote immediately by passing 0 seconds stable threshold
    $promoted = $service->promoteTmpPlaylistIfStable($network, 0);

    expect($promoted)->toBeTrue();
    expect(File::exists($live))->toBeTrue();
    expect(File::get($live))->toBe("#EXTM3U\n#TMP\n");
});
