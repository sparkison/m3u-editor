<?php

namespace Tests\Feature;

use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Services\SharedStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SharedStreamingTest extends TestCase
{
    use RefreshDatabase;

    protected $sharedStreamService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sharedStreamService = app(SharedStreamService::class);
    }

    /** @test */
    public function it_can_create_a_shared_stream()
    {
        $sourceUrl = 'https://example.com/test.m3u8';
        $format = 'hls';

        $streamId = $this->sharedStreamService->createSharedStream($sourceUrl, $format);

        $this->assertNotNull($streamId);
        $this->assertDatabaseHas('shared_streams', [
            'stream_id' => $streamId,
            'source_url' => $sourceUrl,
            'format' => $format,
            'status' => 'starting'
        ]);
    }

    /** @test */
    public function it_can_join_an_existing_stream()
    {
        // Create a stream first
        $sourceUrl = 'https://example.com/test.m3u8';
        $streamId = $this->sharedStreamService->createSharedStream($sourceUrl, 'hls');

        // Mark stream as active
        SharedStream::where('stream_id', $streamId)->update(['status' => 'active']);

        // Join the stream
        $joinResult = $this->sharedStreamService->joinStream($sourceUrl, 'hls', '192.168.1.100');

        $this->assertEquals($streamId, $joinResult['stream_id']);
        $this->assertTrue($joinResult['joined_existing']);
    }

    /** @test */
    public function it_creates_new_stream_when_no_existing_stream_available()
    {
        $sourceUrl = 'https://example.com/test.m3u8';
        
        $joinResult = $this->sharedStreamService->joinStream($sourceUrl, 'hls', '192.168.1.100');

        $this->assertNotNull($joinResult['stream_id']);
        $this->assertFalse($joinResult['joined_existing']);
        $this->assertDatabaseHas('shared_streams', [
            'stream_id' => $joinResult['stream_id'],
            'source_url' => $sourceUrl
        ]);
    }

    /** @test */
    public function it_can_track_clients()
    {
        $streamId = 'test_stream_123';
        $ipAddress = '192.168.1.100';
        $userAgent = 'Test Client/1.0';

        $client = SharedStreamClient::createConnection($streamId, $ipAddress, $userAgent);

        $this->assertDatabaseHas('shared_stream_clients', [
            'stream_id' => $streamId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'status' => 'connected'
        ]);

        $this->assertTrue($client->isActive());
    }

    /** @test */
    public function it_can_disconnect_clients()
    {
        $client = SharedStreamClient::createConnection('test_stream', '192.168.1.100');
        
        $client->disconnect();

        $this->assertDatabaseHas('shared_stream_clients', [
            'client_id' => $client->client_id,
            'status' => 'disconnected'
        ]);
    }

    /** @test */
    public function it_can_cleanup_inactive_clients()
    {
        // Create some clients
        $activeClient = SharedStreamClient::createConnection('test_stream', '192.168.1.100');
        $inactiveClient = SharedStreamClient::createConnection('test_stream', '192.168.1.101');
        
        // Make one client inactive
        $inactiveClient->update(['last_activity_at' => now()->subMinutes(2)]);

        $disconnectedCount = SharedStreamClient::disconnectInactiveClients(60);

        $this->assertEquals(1, $disconnectedCount);
        
        $activeClient->refresh();
        $inactiveClient->refresh();
        
        $this->assertEquals('connected', $activeClient->status);
        $this->assertEquals('disconnected', $inactiveClient->status);
    }

    /** @test */
    public function it_can_stop_streams()
    {
        $streamId = $this->sharedStreamService->createSharedStream('https://example.com/test.m3u8', 'hls');
        
        $success = $this->sharedStreamService->stopStream($streamId);

        $this->assertTrue($success);
        $this->assertDatabaseHas('shared_streams', [
            'stream_id' => $streamId,
            'status' => 'stopped'
        ]);
    }

    /** @test */
    public function it_can_get_stream_url()
    {
        $streamId = $this->sharedStreamService->createSharedStream('https://example.com/test.m3u8', 'hls');
        
        $streamUrl = $this->sharedStreamService->getStreamUrl($streamId, 'hls');

        $this->assertStringContains('/shared/stream/' . $streamId, $streamUrl);
        $this->assertStringContains('format=hls', $streamUrl);
    }

    /** @test */
    public function it_handles_concurrent_clients_properly()
    {
        $sourceUrl = 'https://example.com/test.m3u8';
        
        // Simulate multiple clients joining the same stream
        $result1 = $this->sharedStreamService->joinStream($sourceUrl, 'hls', '192.168.1.100');
        $result2 = $this->sharedStreamService->joinStream($sourceUrl, 'hls', '192.168.1.101');
        $result3 = $this->sharedStreamService->joinStream($sourceUrl, 'hls', '192.168.1.102');

        // All should get the same stream ID (first creates, others join)
        $this->assertEquals($result1['stream_id'], $result2['stream_id']);
        $this->assertEquals($result1['stream_id'], $result3['stream_id']);
        
        // Only the first should create a new stream
        $this->assertFalse($result1['joined_existing']);
        $this->assertTrue($result2['joined_existing']);
        $this->assertTrue($result3['joined_existing']);

        // Should only have one stream in database
        $this->assertEquals(1, SharedStream::where('source_url', $sourceUrl)->count());
    }

    /** @test */
    public function it_can_generate_unique_stream_ids()
    {
        $id1 = SharedStream::generateStreamId();
        $id2 = SharedStream::generateStreamId();

        $this->assertNotEquals($id1, $id2);
        $this->assertStringStartsWith('shared_', $id1);
        $this->assertStringStartsWith('shared_', $id2);
        $this->assertEquals(23, strlen($id1)); // 'shared_' + 16 random chars
    }

    /** @test */
    public function it_can_cleanup_old_streams()
    {
        // Create some old stopped streams
        SharedStream::create([
            'stream_id' => 'old_stream_1',
            'source_url' => 'https://example.com/old1.m3u8',
            'format' => 'hls',
            'status' => 'stopped',
            'stopped_at' => now()->subDays(2)
        ]);

        SharedStream::create([
            'stream_id' => 'recent_stream',
            'source_url' => 'https://example.com/recent.m3u8',
            'format' => 'hls',
            'status' => 'stopped',
            'stopped_at' => now()->subHours(2)
        ]);

        $cleanedCount = SharedStream::cleanupOldStreams(24);

        $this->assertEquals(1, $cleanedCount);
        $this->assertDatabaseMissing('shared_streams', ['stream_id' => 'old_stream_1']);
        $this->assertDatabaseHas('shared_streams', ['stream_id' => 'recent_stream']);
    }
}
