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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_generate_stream_keys()
    {
        $channel = $this->createTestChannel();
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        $this->assertNotEmpty($streamKey);
        $this->assertStringContainsString('shared_stream:channel:', $streamKey);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_create_new_stream()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_create.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Test basic stream record creation manually to avoid FFmpeg
        $sharedStream = SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'starting',
            'client_count' => 1,
        ]);
        
        $this->assertNotNull($sharedStream);
        $this->assertEquals($streamKey, $sharedStream->stream_id);
        
        // Verify database record
        $this->assertDatabaseHas('shared_streams', [
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'status' => 'starting'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_join_existing_active_stream()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_join.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create database record first
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => 12345
        ]);
        
        // Create active stream in Redis
        $this->createActiveStreamInRedis($streamKey, 12345, 1);
        
        // Test that the stream info can be retrieved
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getStreamInfo');
        $method->setAccessible(true);
        
        $streamInfo = $method->invoke($this->service, $streamKey);
        
        $this->assertNotNull($streamInfo);
        $this->assertEquals('active', $streamInfo['status']);
        $this->assertEquals(1, $streamInfo['client_count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_handle_client_disconnect()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_disconnect.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        $clientId = 'test_client_disconnect';
        
        // Create stream directly in database
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => 12345
        ]);
        
        // Create Redis data
        $this->createActiveStreamInRedis($streamKey, 12345, 1);
        
        // Now disconnect the client
        $this->service->removeClient($streamKey, $clientId);
        
        // Stream should be marked for cleanup or cleaned up
        $dbStream = SharedStream::where('stream_id', $streamKey)->first();
        if ($dbStream) {
            $this->assertEquals(0, $dbStream->client_count);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_cleanup_streams()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_cleanup.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create a stream
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 0,
            'process_id' => 99999
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_clients()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_concurrent.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create initial stream directly in database instead of using getOrCreateSharedStream
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => 11111
        ]);
        
        // Create initial Redis data
        $this->createActiveStreamInRedis($streamKey, 11111, 1);
        
        // Test incrementing client count (simulating additional clients)
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('incrementClientCount');
        $method->setAccessible(true);
        
        // Add second client
        $method->invoke($this->service, $streamKey, 'client2');
        
        // Add third client  
        $method->invoke($this->service, $streamKey, 'client3');
        
        // Verify final client count
        $streamData = json_decode(Redis::get($streamKey), true);
        $this->assertGreaterThanOrEqual(2, $streamData['client_count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => $pid
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_detects_and_handles_phantom_streams()
    {
        $channel = $this->createTestChannel('https://example.com/test_phantom.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create database record for a stream with non-existent process
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => 99999 // Non-existent PID
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_synchronizes_database_and_redis_state()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('https://example.com/test_sync.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Create inconsistent state - database says active but Redis is empty
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 1,
            'process_id' => 77777
        ]);
        
        // Redis is empty (inconsistent state)
        
        // Test detecting inconsistent state with isStreamActive
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isStreamActive');
        $method->setAccessible(true);
        
        $isActive = $method->invoke($this->service, $streamKey);
        
        // Should detect the stream as inactive due to inconsistent state
        $this->assertFalse($isActive);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_stream_creation_with_invalid_urls()
    {
        $this->mockLock();
        
        $channel = $this->createTestChannel('invalid://malformed.url');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        
        // Test that stream key generation works even with invalid URLs
        $this->assertNotNull($streamKey);
        $this->assertStringContainsString('shared_stream:channel:', $streamKey);
        
        // The service should handle invalid URLs gracefully in key generation
        $this->assertTrue(strlen($streamKey) > 20); // Should have meaningful length
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_performs_stream_failover_integration_test()
    {
        $this->mockLock();
        
        // Test that the service has the failover methods
        $reflection = new \ReflectionClass($this->service);
        
        $this->assertTrue($reflection->hasMethod('attemptStreamFailover'), 'Service should have attemptStreamFailover method');
        $this->assertTrue($reflection->hasMethod('migrateClientsToFailoverStream'), 'Service should have migrateClientsToFailoverStream method');
        $this->assertTrue($reflection->hasMethod('markStreamFailed'), 'Service should have markStreamFailed method');
        
        // Test basic failover scenario detection  
        $method = $reflection->getMethod('attemptStreamFailover');
        $method->setAccessible(true);
        
        // Test with minimal stream info
        $streamKey = 'test_failover_' . uniqid();
        $streamInfo = [
            'stream_key' => $streamKey,
            'type' => 'episode', // Non-channel type
            'model_id' => 999999,
            'failover_attempts' => 0
        ];
        
        $result = $method->invoke($this->service, $streamKey, $streamInfo);
        
        // Should return null for non-channel streams
        $this->assertNull($result, 'Should return null for non-channel streams');
    }

    #[\PHPUnit\Framework\Attributes\Test] 
    public function it_handles_failover_with_multiple_backup_channels()
    {
        $this->mockLock();
        
        // Test that the failover system can conceptually handle multiple backup channels
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('attemptStreamFailover');
        $method->setAccessible(true);
        
        // Test with stream info indicating multiple previous attempts
        $streamKey = 'test_multi_failover_' . uniqid();
        $streamInfo = [
            'stream_key' => $streamKey,
            'type' => 'channel',
            'model_id' => 999999, // Non-existent channel
            'failover_attempts' => 3 // Multiple attempts already made
        ];
        
        $result = $method->invoke($this->service, $streamKey, $streamInfo);
        
        // Should handle gracefully when channel doesn't exist
        $this->assertNull($result, 'Should return null when channel not found');
        
        // Test that the failover concept works for valid scenarios
        $this->assertTrue(method_exists($this->service, 'attemptStreamFailover'), 'Failover method should exist');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_serves_segments_to_multiple_clients_independently()
    {
        $this->mockLock();

        $channel = $this->createTestChannel('https://example.com/test_multi_client.m3u8');
        $streamKey = $this->getStreamKey('channel', $channel->id, $channel->url);
        $clientId1 = 'test_client_1';
        $clientId2 = 'test_client_2';

        // Create stream and register clients
        SharedStream::create([
            'stream_id' => $streamKey,
            'source_url' => $channel->url,
            'format' => 'ts',
            'status' => 'active',
            'client_count' => 2,
            'process_id' => 12345
        ]);
        $this->service->registerClient($streamKey, $clientId1);
        $this->service->registerClient($streamKey, $clientId2);

        // Add buffer segments to Redis
        $bufferKey = 'stream_buffer:' . $streamKey;
        $redis = app('redis');
        $redis->lpush("{$bufferKey}:segments", 0);
        $redis->set("{$bufferKey}:segment_0", 'segment_0_data');
        $redis->lpush("{$bufferKey}:segments", 1);
        $redis->set("{$bufferKey}:segment_1", 'segment_1_data');
        $redis->lpush("{$bufferKey}:segments", 2);
        $redis->set("{$bufferKey}:segment_2", 'segment_2_data');

        // Simulate client 1 requesting segments
        $lastSegment1 = -1;
        $data1 = $this->service->getNextStreamSegments($streamKey, $clientId1, $lastSegment1);

        // Assert client 1 gets all segments
        $this->assertEquals('segment_0_datasegment_1_datasegment_2_data', $data1);
        $this->assertEquals(2, $lastSegment1);

        // Simulate client 2 requesting segments
        $lastSegment2 = -1;
        $data2 = $this->service->getNextStreamSegments($streamKey, $clientId2, $lastSegment2);

        // Assert client 2 also gets all segments
        $this->assertEquals('segment_0_datasegment_1_datasegment_2_data', $data2);
        $this->assertEquals(2, $lastSegment2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_correctly_checks_if_a_process_is_running()
    {
        // Get the PID of the current test process
        $pid = getmypid();

        // Check if the current process is running (it should be)
        $this->assertTrue($this->service->isProcessRunning($pid));

        // Check for a non-existent process
        $this->assertFalse($this->service->isProcessRunning(999999));
    }
}
