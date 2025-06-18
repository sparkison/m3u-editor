<?php

namespace Tests\Unit;

use App\Services\SharedStreamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SharedStreamServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected SharedStreamService $sharedStreamService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sharedStreamService = app(SharedStreamService::class);
        
        // Clear Redis cache to ensure clean test environment
        Redis::flushdb();
    }

    /** @test */
    public function it_generates_valid_stream_keys()
    {
        $type = 'test';
        $modelId = 123;
        $streamUrl = 'https://example.com/test.m3u8';

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->sharedStreamService);
        $method = $reflection->getMethod('getStreamKey');
        $method->setAccessible(true);

        $streamKey1 = $method->invoke($this->sharedStreamService, $type, $modelId, $streamUrl);
        $streamKey2 = $method->invoke($this->sharedStreamService, $type, $modelId, $streamUrl);

        // Same inputs should generate same key
        $this->assertEquals($streamKey1, $streamKey2);
        
        // Should be a valid MD5 hash
        $this->assertEquals(32, strlen($streamKey1));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $streamKey1);
    }

    /** @test */
    public function it_handles_redis_key_prefixes_correctly()
    {
        $testKey = 'test_stream_key';
        $testData = ['status' => 'active', 'test' => true];

        // Use reflection to access private methods
        $reflection = new \ReflectionClass($this->sharedStreamService);
        
        $setMethod = $reflection->getMethod('setStreamInfo');
        $setMethod->setAccessible(true);
        
        $getMethod = $reflection->getMethod('getStreamInfo');
        $getMethod->setAccessible(true);

        // Set data
        $setMethod->invoke($this->sharedStreamService, $testKey, $testData);

        // Get data back
        $retrievedData = $getMethod->invoke($this->sharedStreamService, $testKey);

        $this->assertNotNull($retrievedData);
        $this->assertEquals($testData['status'], $retrievedData['status']);
        $this->assertTrue($retrievedData['test']);
    }

    /** @test */
    public function it_validates_stream_activity_correctly()
    {
        $testKey = 'active_stream_test';
        
        // Use reflection to access private methods
        $reflection = new \ReflectionClass($this->sharedStreamService);
        
        $setMethod = $reflection->getMethod('setStreamInfo');
        $setMethod->setAccessible(true);
        
        $isActiveMethod = $reflection->getMethod('isStreamActive');
        $isActiveMethod->setAccessible(true);

        // Initially no stream should be active
        $this->assertFalse($isActiveMethod->invoke($this->sharedStreamService, $testKey));

        // Set stream data
        $setMethod->invoke($this->sharedStreamService, $testKey, ['status' => 'active']);

        // Now should be active
        $this->assertTrue($isActiveMethod->invoke($this->sharedStreamService, $testKey));
    }

    /** @test */
    public function it_calculates_client_counts_accurately()
    {
        $streamKey = 'client_count_test';
        
        $result = $this->sharedStreamService->getClientCount($streamKey);
        
        // Should return 0 for non-existent stream
        $this->assertEquals(0, $result);
    }

    /** @test */
    public function it_handles_empty_or_invalid_stream_data()
    {
        $invalidKey = 'non_existent_stream';
        
        $stats = $this->sharedStreamService->getStreamStats($invalidKey);
        
        // Should return null for non-existent stream
        $this->assertNull($stats);
    }

    /** @test */
    public function it_properly_formats_stream_storage_directories()
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->sharedStreamService);
        $method = $reflection->getMethod('getStreamStorageDir');
        $method->setAccessible(true);

        $streamKey = 'test_stream_123';
        $expectedPath = "shared_streams/{$streamKey}";
        
        $actualPath = $method->invoke($this->sharedStreamService, $streamKey);
        
        $this->assertEquals($expectedPath, $actualPath);
    }

    /** @test */
    public function it_handles_process_validation_safely()
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->sharedStreamService);
        $method = $reflection->getMethod('isProcessRunning');
        $method->setAccessible(true);

        // Test with obviously invalid PID
        $invalidPid = 999999;
        $result = $method->invoke($this->sharedStreamService, $invalidPid);
        
        // Should return false for invalid PID
        $this->assertFalse($result);
    }

    /** @test */
    public function it_maintains_consistent_cache_prefix_usage()
    {
        // Test that cache prefixes are used consistently
        $reflection = new \ReflectionClass($this->sharedStreamService);
        
        $cachePrefix = $reflection->getConstant('CACHE_PREFIX');
        $clientPrefix = $reflection->getConstant('CLIENT_PREFIX');
        $bufferPrefix = $reflection->getConstant('BUFFER_PREFIX');
        
        $this->assertEquals('shared_stream:', $cachePrefix);
        $this->assertEquals('stream_clients:', $clientPrefix);
        $this->assertEquals('stream_buffer:', $bufferPrefix);
    }

    /** @test */
    public function it_returns_empty_array_for_no_active_streams()
    {
        $activeStreams = $this->sharedStreamService->getAllActiveStreams();
        
        $this->assertIsArray($activeStreams);
        $this->assertEmpty($activeStreams);
    }

    /** @test */
    public function it_handles_cleanup_operations_safely()
    {
        $nonExistentKey = 'definitely_does_not_exist_stream';
        
        // Should not throw exceptions when cleaning up non-existent streams
        $result = $this->sharedStreamService->cleanupStream($nonExistentKey, true);
        
        // Should return true even for non-existent streams (already cleaned)
        $this->assertTrue($result);
    }
}
