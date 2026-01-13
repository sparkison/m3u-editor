<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
});

afterEach(function () {
    // Clean up any created test directories
    $networksPath = storage_path('app/networks');
    if (File::exists($networksPath)) {
        // Only clean up test network directories (ones with 'test-' prefix or UUIDs)
        foreach (File::directories($networksPath) as $dir) {
            $dirName = basename($dir);
            // Clean up our test network directories
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $dirName)) {
                File::deleteDirectory($dir);
            }
        }
    }
});

describe('Network HLS Controller', function () {

    describe('playlist endpoint', function () {

        it('returns 404 when broadcast is not enabled', function () {
            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => false,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertNotFound();
        });

        it('returns 503 when broadcast is enabled but no playlist exists', function () {
            $network = Network::factory()->for($this->user)->broadcasting()->create();

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertStatus(503);
            $response->assertHeader('Retry-After', '5');
        });

        it('returns playlist content when broadcast is active and playlist exists', function () {
            $network = Network::factory()->for($this->user)->activeBroadcast()->create();

            // Create the HLS storage directory and playlist
            $hlsPath = $network->getHlsStoragePath();
            File::ensureDirectoryExists($hlsPath);

            $playlistContent = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXTINF:6.000,
live000001.ts
#EXTINF:6.000,
live000002.ts
#EXTINF:6.000,
live000003.ts
M3U8;

            File::put("{$hlsPath}/live.m3u8", $playlistContent);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/vnd.apple.mpegurl; charset=UTF-8');
            $response->assertHeader('Access-Control-Allow-Origin', '*');
            $response->assertSee('#EXTM3U');
            $response->assertSee('live000001.ts');
        });
    });

    describe('segment endpoint', function () {

        it('returns 404 when broadcast is not enabled', function () {
            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => false,
            ]);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertNotFound();
        });

        it('returns 404 when segment does not exist', function () {
            $network = Network::factory()->for($this->user)->broadcasting()->create();

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertNotFound();
        });

        it('returns segment content when it exists', function () {
            $network = Network::factory()->for($this->user)->activeBroadcast()->create();

            // Create the HLS storage directory and a segment
            $hlsPath = $network->getHlsStoragePath();
            File::ensureDirectoryExists($hlsPath);

            // Create a small dummy .ts file (just some bytes for testing)
            $dummyContent = str_repeat("\x00", 188 * 10); // 188 bytes is TS packet size
            File::put("{$hlsPath}/live000001.ts", $dummyContent);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'video/MP2T');
            $response->assertHeader('Access-Control-Allow-Origin', '*');
        });
    });

    describe('storage path', function () {

        it('creates storage directory under networks/{uuid}', function () {
            $network = Network::factory()->for($this->user)->create();

            $expectedPath = storage_path("app/networks/{$network->uuid}");
            expect($network->getHlsStoragePath())->toBe($expectedPath);
        });

        it('can create the storage directory', function () {
            $network = Network::factory()->for($this->user)->create();

            $hlsPath = $network->getHlsStoragePath();
            File::ensureDirectoryExists($hlsPath);

            expect(File::isDirectory($hlsPath))->toBeTrue();
        });
    });

    describe('model helper methods', function () {

        it('isBroadcasting returns false when not started', function () {
            $network = Network::factory()->for($this->user)->broadcasting()->create();

            expect($network->isBroadcasting())->toBeFalse();
        });

        it('isBroadcasting returns true when actively broadcasting', function () {
            $network = Network::factory()->for($this->user)->activeBroadcast()->create();

            expect($network->isBroadcasting())->toBeTrue();
        });

        it('getStreamUrlAttribute returns HLS URL when broadcasting', function () {
            $network = Network::factory()->for($this->user)->activeBroadcast()->create();

            expect($network->stream_url)->toContain('/live.m3u8');
            expect($network->stream_url)->toContain($network->uuid);
        });

        it('getStreamUrlAttribute returns legacy URL when not broadcasting', function () {
            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => false,
            ]);

            expect($network->stream_url)->toContain('/stream');
            expect($network->stream_url)->not->toContain('live.m3u8');
        });
    });
});
