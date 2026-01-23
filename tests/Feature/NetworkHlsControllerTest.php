<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Network HLS Controller', function () {

    describe('playlist endpoint', function () {

        it('returns 404 when broadcast is not enabled', function () {
            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => false,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertNotFound();
        })->group('serial');

        it('returns 503 when proxy returns 404 (broadcast not started)', function () {
            Http::fake([
                '*/broadcast/*/live.m3u8' => Http::response('Not found', 404),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertStatus(503);
            $response->assertHeader('Retry-After', '5');
        })->group('serial');

        it('returns playlist content when proxy returns successfully', function () {
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

            Http::fake([
                '*/broadcast/*/live.m3u8' => Http::response($playlistContent, 200),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertOk();
            // Content-Type may include charset on some platforms - accept either
            $ct = $response->headers->get('Content-Type');
            expect(str_contains($ct, 'application/vnd.apple.mpegurl'))->toBeTrue();
            $response->assertHeader('Access-Control-Allow-Origin', '*');
            $response->assertSee('#EXTM3U');
        })->group('serial');

        it('rewrites segment URLs to Laravel routes', function () {
            $playlistContent = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXTINF:6.000,
live000001.ts
#EXTINF:6.000,
live000002.ts
M3U8;

            Http::fake([
                '*/broadcast/*/live.m3u8' => Http::response($playlistContent, 200),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertOk();
            $content = $response->getContent();

            // Should contain rewritten URLs pointing to our Laravel routes
            expect($content)->toContain($network->uuid);
            expect($content)->toContain('live000001');
            // Should NOT contain raw .ts filenames anymore
            expect($content)->not->toContain("live000001.ts\n");
        })->group('serial');

        it('returns 503 when proxy is unavailable', function () {
            Http::fake([
                '*/broadcast/*/live.m3u8' => Http::response('Service unavailable', 500),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));

            $response->assertStatus(503);
            $response->assertHeader('Retry-After', '5');
        })->group('serial');
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
        })->group('serial');

        it('returns 400 for invalid segment names', function () {
            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'invalid_segment',
            ]));

            $response->assertStatus(400);
        })->group('serial');

        it('returns segment content when proxy returns successfully', function () {
            // Create a small dummy .ts content
            $segmentContent = str_repeat("\x00", 188 * 10);

            Http::fake([
                '*/broadcast/*/segment/live000001.ts' => Http::response($segmentContent, 200),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'video/MP2T');
            $response->assertHeader('Access-Control-Allow-Origin', '*');
        })->group('serial');

        it('returns 404 when proxy returns 404 for segment', function () {
            Http::fake([
                '*/broadcast/*/segment/live000001.ts' => Http::response('Not found', 404),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertNotFound();
        })->group('serial');

        it('returns 503 when proxy is unavailable for segment', function () {
            Http::fake([
                '*/broadcast/*/segment/live000001.ts' => Http::response('Service unavailable', 500),
            ]);

            $network = Network::factory()->for($this->user)->create([
                'broadcast_enabled' => true,
            ]);

            $response = $this->get(route('network.hls.segment', [
                'network' => $network->uuid,
                'segment' => 'live000001',
            ]));

            $response->assertStatus(503);
        })->group('serial');
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
