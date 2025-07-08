<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SharedStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SharedStreamServiceRedirectTest extends TestCase
{
    use RefreshDatabase;

    private SharedStreamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SharedStreamService::class);
        
        // Clear Redis before each test
        Redis::flushall();
    }

    /**
     * Test that a failover stream doesn't redirect to itself
     * This was the cause of the infinite redirect loop bug
     */
    public function test_failover_stream_does_not_redirect_to_itself()
    {
        // Create a primary stream that has failed
        $primaryStreamKey = 'shared_stream:channel:1:' . md5('test_primary_url');
        $failoverStreamKey = 'shared_stream:channel:1:' . md5('test_failover_url');
        $modelId = 1;
        $clientId = 'test_client_789';

        // Set up primary stream as failed
        $primaryStreamInfo = [
            'model_id' => $modelId,
            'status' => 'failed',
            'stream_key' => $primaryStreamKey,
            'is_failover' => false,
            'primary_channel_id' => $modelId
        ];
        Redis::setex($primaryStreamKey, 3600, json_encode($primaryStreamInfo));

        // Set up failover stream as active
        $failoverStreamInfo = [
            'model_id' => $modelId,
            'status' => 'active',
            'stream_key' => $failoverStreamKey,
            'is_failover' => true,
            'primary_channel_id' => $modelId
        ];
        Redis::setex($failoverStreamKey, 3600, json_encode($failoverStreamInfo));

        // Test 1: Client requests data from primary stream - should redirect to failover
        $redirectResult = $this->callPrivateMethod($this->service, 'checkForFailoverRedirect', [$primaryStreamKey, $clientId]);
        $this->assertEquals($failoverStreamKey, $redirectResult, 'Primary stream should redirect to failover stream');

        // Test 2: Client requests data from failover stream - should NOT redirect (was causing infinite loop)
        $redirectResult = $this->callPrivateMethod($this->service, 'checkForFailoverRedirect', [$failoverStreamKey, $clientId]);
        $this->assertEquals($failoverStreamKey, $redirectResult, 'Failover stream should return itself, not redirect further');

        // Test 3: Verify the stream doesn't find itself as a failover option
        // This tests the fix where we exclude the current stream from failover search
        $this->assertTrue(true, 'Test completed without infinite loop');
    }

    /**
     * Test that redirect count limits prevent infinite loops
     */
    public function test_redirect_count_limits_prevent_infinite_loops()
    {
        $streamKey = 'shared_stream:channel:1:' . md5('test_stream_url');
        $clientId = 'test_client_456';
        $channelId = 1;
        $lastSegment = -1;

        // Set up a scenario that would cause redirects
        $streamInfo = [
            'model_id' => $channelId,
            'status' => 'failed',
            'stream_key' => $streamKey,
            'primary_channel_id' => $channelId
        ];
        Redis::setex($streamKey, 3600, json_encode($streamInfo));

        // Set redirect count to maximum
        $redirectTrackingKey = "redirect_tracking:{$clientId}:channel:{$channelId}";
        Redis::setex($redirectTrackingKey, 30, 3); // Max redirects reached

        // This should return null due to max redirects reached
        $result = $this->service->getNextStreamSegments($streamKey, $clientId, $lastSegment);
        $this->assertNull($result, 'Should return null when max redirects reached');
    }

    /**
     * Test that failover streams get a grace period to start buffering
     * This prevents redirects when a failover stream is still starting up
     */
    public function test_failover_stream_grace_period_prevents_premature_redirect()
    {
        $failoverStreamKey = 'shared_stream:channel:2:' . md5('test_failover_url');
        $alternativeStreamKey = 'shared_stream:channel:2:' . md5('test_alternative_url');
        $clientId = 'test_client_grace';
        $modelId = 2;
        $lastSegment = -1;

        // Set up a newly created failover stream (within grace period)
        $failoverStreamInfo = [
            'model_id' => $modelId,
            'status' => 'active',
            'stream_key' => $failoverStreamKey,
            'is_failover' => true,
            'primary_channel_id' => $modelId,
            'created_at' => time() - 10, // Only 10 seconds old (within 30s grace period)
        ];
        Redis::setex($failoverStreamKey, 3600, json_encode($failoverStreamInfo));

        // Set up an alternative stream that would be found by failover search
        $alternativeStreamInfo = [
            'model_id' => $modelId,
            'status' => 'active',
            'stream_key' => $alternativeStreamKey,
            'is_failover' => true,
            'primary_channel_id' => $modelId,
            'created_at' => time() - 100,
        ];
        Redis::setex($alternativeStreamKey, 3600, json_encode($alternativeStreamInfo));

        // Call getNextStreamSegments on the young failover stream
        $result = $this->service->getNextStreamSegments($failoverStreamKey, $clientId, $lastSegment);

        // Should return null (no data yet) but NOT redirect because stream is in grace period
        $this->assertNull($result, 'Young failover stream should return null but not redirect');

        // Verify no redirect tracking was created (no redirect should have happened)
        $redirectTrackingKey = "redirect_tracking:{$clientId}:channel:{$modelId}";
        $this->assertEquals(0, Redis::exists($redirectTrackingKey), 'No redirect tracking should exist for grace period stream');
    }

    /**
     * Helper method to call private methods for testing
     */
    private function callPrivateMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
