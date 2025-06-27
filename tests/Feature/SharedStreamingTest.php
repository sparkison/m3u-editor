<?php

namespace Tests\Feature;

use App\Models\SharedStream;
use App\Models\Channel;
use App\Services\SharedStreamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Tests\TestCase;
use Mockery;

class SharedStreamingTest extends TestCase
{
    use DatabaseTransactions;

    protected SharedStreamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(SharedStreamService::class);
        Redis::flushdb();
        Carbon::setTestNow();
        
        // Mock Log to avoid excessive logging during tests
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturn(true);
        Log::shouldReceive('debug')->andReturn(true);
        Log::shouldReceive('warning')->andReturn(true);
        Log::shouldReceive('error')->andReturn(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Redis::flushdb();
        Mockery::close();
        parent::tearDown();
    }

    private function mockLock($shouldGetLock = true)
    {
        $lockMock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lockMock->shouldReceive('get')->andReturn($shouldGetLock);
        if ($shouldGetLock) {
            $lockMock->shouldReceive('release')->andReturn(true);
        }

        Cache::shouldReceive('lock')->andReturn($lockMock);
        return $lockMock;
    }

    private function createTestChannel(string $url = 'http://example.com/test.m3u8'): Channel
    {
        return Channel::factory()->create(['url' => $url]);
    }

    private function getStreamKey(string $type, int $modelId, string $sourceUrl): string
    {
        // Use reflection to access the protected getStreamKey method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getStreamKey');
        $method->setAccessible(true);
        
        return $method->invoke($this->service, $type, $modelId, $sourceUrl);
    }

    private function createActiveStreamInRedis(string $streamKey, int $pid = 12345, int $clientCount = 1): void
    {
        $streamData = [
            'status' => 'active',
            'pid' => $pid,
            'client_count' => $clientCount,
            'created_at' => Carbon::now()->toISOString(),
            'updated_at' => Carbon::now()->toISOString(),
        ];
        
        Redis::set($streamKey, json_encode($streamData));
    }

    /** @test */
    public function it_can_generate_stream_keys()
    {
        $channel = $this->createTestChannel();
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        $this->assertNotEmpty($streamKey);
        $this->assertStringContainsString('shared_stream:channel:', $streamKey);
    }

    /** @test */
    public function it_can_create_new_stream()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_create.m3u8');
        $clientId = 'test_client_create';
        
        $streamInfo = $this->service->getOrCreateSharedStream(
            'channel', 
            $channel->id, 
            $channel->url, 
            'Test Create Stream', 
            'ts', 
            $clientId, 
            []
        );

        $this->assertNotNull($streamInfo['stream_key']);
        $this->assertTrue($streamInfo['is_new_stream']);
        
        // Verify database record
        $this->assertDatabaseHas('shared_streams', [
            'stream_id' => $streamInfo['stream_key'],
            'source_url' => $channel->url,
            'title' => 'Test Create Stream',
            'status' => 'starting'
        ]);
    }

    /** @test */
    public function it_can_join_existing_active_stream()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_join.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create database record first
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'title' => 'Test Join Stream',
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'pid' => 12345
        ]);
        
        // Create active stream in Redis
        $this->createActiveStreamInRedis($streamKey, 12345, 1);
        
        $clientId = 'test_client_join';
        
        $streamInfo = $this->service->getOrCreateSharedStream(
            'channel', 
            $channel->id, 
            $channel->url, 
            'Test Join Stream', 
            'ts', 
            $clientId, 
            []
        );

        $this->assertEquals($streamKey, $streamInfo['stream_key']);
        $this->assertFalse($streamInfo['is_new_stream']);
    }

    /** @test */
    public function it_can_handle_client_disconnect()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_disconnect.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        $clientId = 'test_client_disconnect';
        
        // Create stream first
        $this->service->getOrCreateSharedStream(
            'channel', 
            $channel->id, 
            $channel->url, 
            'Test Disconnect Stream', 
            'ts', 
            $clientId, 
            []
        );
        
        // Now disconnect the client
        $this->service->removeClient($streamKey, $clientId);
        
        // Stream should be marked for cleanup or cleaned up
        $dbStream = SharedStream::where('stream_id', $streamKey)->first();
        if ($dbStream) {
            $this->assertEquals(0, $dbStream->client_count);
        }
    }

    /** @test */
    public function it_can_cleanup_streams()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_cleanup.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create a stream
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'title' => 'Test Cleanup Stream',
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 0,
            'pid' => 99999
        ]);
        
        // Add some Redis data
        $this->createActiveStreamInRedis($streamKey, 99999, 0);
        Redis::set($streamKey . ':buffer:0', 'test_segment_data');
        Redis::set($streamKey . ':buffer:1', 'test_segment_data_2');
        
        // Cleanup the stream using the public stopStream method
        $success = $this->service->stopStream($streamKey);
        $this->assertTrue($success);
        
        // Verify database cleanup
        $this->assertDatabaseMissing('shared_streams', [
            'stream_id' => $streamKey
        ]);
        
        // Verify Redis cleanup
        $this->assertNull(Redis::get($streamKey));
        $this->assertNull(Redis::get($streamKey . ':buffer:0'));
        $this->assertNull(Redis::get($streamKey . ':buffer:1'));
    }

    /** @test */
    public function it_handles_concurrent_clients()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_concurrent.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // First client creates stream
        $streamInfo1 = $this->service->getOrCreateSharedStream(
            'channel', $channel->id, $channel->url, 'Concurrent Test', 'ts', 'client1', []
        );
        
        // Simulate stream becoming active
        $this->createActiveStreamInRedis($streamKey, 11111, 1);
        SharedStream::where('stream_id', $streamKey)->update(['status' => 'active', 'pid' => 11111]);
        
        // Second client joins
        $streamInfo2 = $this->service->getOrCreateSharedStream(
            'channel', $channel->id, $channel->url, 'Concurrent Test', 'ts', 'client2', []
        );
        
        // Third client joins
        $streamInfo3 = $this->service->getOrCreateSharedStream(
            'channel', $channel->id, $channel->url, 'Concurrent Test', 'ts', 'client3', []
        );
        
        $this->assertEquals($streamKey, $streamInfo1['stream_key']);
        $this->assertEquals($streamKey, $streamInfo2['stream_key']);
        $this->assertEquals($streamKey, $streamInfo3['stream_key']);
        
        $this->assertTrue($streamInfo1['is_new_stream']);
        $this->assertFalse($streamInfo2['is_new_stream']);
        $this->assertFalse($streamInfo3['is_new_stream']);
    }

    /** @test */
    public function it_properly_cleans_up_redis_keys_on_stream_stop()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_redis_cleanup.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        $pid = 88888;
        
        // Create stream with some buffer data
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'title' => 'Redis Cleanup Test',
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'pid' => $pid
        ]);
        
        $this->createActiveStreamInRedis($streamKey, $pid, 1);
        
        // Add buffer segments
        Redis::set($streamKey . ':buffer:0', 'segment_0_data');
        Redis::set($streamKey . ':buffer:1', 'segment_1_data');
        Redis::set($streamKey . ':buffer:2', 'segment_2_data');
        Redis::set($streamKey . ':metadata', json_encode(['last_segment' => 2]));
        
        // Stop the stream
        $success = $this->service->stopStream($streamKey);
        $this->assertTrue($success);
        
        // Verify all Redis keys are cleaned up
        $this->assertNull(Redis::get($streamKey));
        $this->assertNull(Redis::get($streamKey . ':buffer:0'));
        $this->assertNull(Redis::get($streamKey . ':buffer:1'));
        $this->assertNull(Redis::get($streamKey . ':buffer:2'));
        $this->assertNull(Redis::get($streamKey . ':metadata'));
        
        // Verify database cleanup
        $this->assertDatabaseMissing('shared_streams', [
            'stream_id' => $streamKey
        ]);
    }

    /** @test */
    public function it_detects_and_handles_phantom_streams()
    {
        $channel = $this->createTestChannel('https://example.com/test_phantom.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create database record for a stream with non-existent process
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'title' => 'Phantom Stream Test',
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'pid' => 99999 // Non-existent PID
        ]);
        
        $this->createActiveStreamInRedis($streamKey, 99999, 1);
        
        // Try to get stream info - should detect phantom and clean up
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isStreamActive');
        $method->setAccessible(true);
        
        $isActive = $method->invoke($this->service, $streamKey);
        
        // The phantom stream should be detected as inactive
        $this->assertFalse($isActive);
    }

    /** @test */
    public function it_synchronizes_database_and_redis_state()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_sync.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create inconsistent state - database says active but Redis is empty
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'title' => 'Sync Test Stream',
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'pid' => 77777
        ]);
        
        // Redis is empty (inconsistent state)
        
        // Try to create stream - should detect inconsistency and handle it
        $streamInfo = $this->service->getOrCreateSharedStream(
            'channel', 
            $channel->id, 
            $channel->url, 
            'Sync Test Stream', 
            'ts', 
            'sync_client', 
            []
        );
        
        // Should create a new stream since the old one was phantom
        $this->assertNotNull($streamInfo['stream_key']);
    }

    /** @test */
    public function it_handles_stream_creation_with_invalid_urls()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('invalid://malformed.url');
        
        // This should still create the stream record (validation happens elsewhere)
        $streamInfo = $this->service->getOrCreateSharedStream(
            'channel', 
            $channel->id, 
            $channel->url, 
            'Invalid URL Test', 
            'ts', 
            'invalid_client', 
            []
        );
        
        $this->assertNotNull($streamInfo['stream_key']);
        $this->assertTrue($streamInfo['is_new_stream']);
    }
}
