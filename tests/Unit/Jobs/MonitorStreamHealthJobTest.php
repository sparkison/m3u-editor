<?php

namespace Tests\Unit\Jobs; // Escaped for subtask

use App\Jobs\MonitorStreamHealthJob; // Escaped
use App\Services\HlsStreamService; // Escaped
use App\Services\ProxyService; // Escaped
use App\Models\Channel; // Escaped
use App\Models\Episode; // Escaped
use App\Models\Playlist; // Escaped
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase; // Escaped
// use Illuminate\Foundation\Testing\RefreshDatabase; // Escaped

class MonitorStreamHealthJobTest extends TestCase
{
    // use RefreshDatabase; // Escaped

    protected $hlsStreamServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hlsStreamServiceMock = $this->mock(HlsStreamService::class);

        Config::set('streaming.monitor_job_interval_seconds', 10);
        Config::set('streaming.hls_segment_age_multiplier', 3);
        Config::set('streaming.hls_segment_grace_period_seconds', 20);
        Config::set('streaming.monitor_job_tries', 1);
        Config::set('streaming.monitor_job_backoff', [10]);
        Config::set('proxy.queue_priority_hls_monitor', 'test_monitor_queue');

        if (!Mockery::getContainer() || !Mockery::getContainer()->hasDefinition(ProxyService::class)) {
             Mockery::mock('overload:' . ProxyService::class) // No leading backslash for overload if class is imported with `use`
                ->shouldReceive('getStreamSettings')
                ->zeroOrMoreTimes()
                ->andReturn(['ffmpeg_hls_time' => 4]);
        } else {
            ProxyService::shouldReceive('getStreamSettings')
                ->zeroOrMoreTimes()
                ->andReturn(['ffmpeg_hls_time' => 4]);
        }

        Storage::fake('app');
        Queue::fake();

        Cache::partialMock();
        Redis::partialMock();
        Log::partialMock();
        File::partialMock();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createJobInstance(array $overrides = []): MonitorStreamHealthJob
    {
        $defaults = [
            'streamType' => 'channel',
            'activeStreamId' => 101,
            'originalModelId' => 100,
            'originalModelTitle' => 'Test Channel Original',
            'playlistIdOfActiveStream' => 1,
            'streamSourceIds' => [101, 102, 103],
            'currentIndexInSourceIds' => 0,
        ];
        $params = array_merge($defaults, $overrides);

        return new MonitorStreamHealthJob(
            $params['streamType'],
            $params['activeStreamId'],
            $params['originalModelId'],
            $params['originalModelTitle'],
            $params['playlistIdOfActiveStream'],
            $params['streamSourceIds'],
            $params['currentIndexInSourceIds']
        );
    }

    // Test methods will be added in subsequent steps.
}
