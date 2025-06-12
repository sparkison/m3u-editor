<?php

namespace Tests\Unit\Jobs;

use App\Jobs\MonitorStreamHealthJob;
use App\Services\HlsStreamService;
use App\Services\ProxyService;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
// use Illuminate\Foundation\Testing\RefreshDatabase;

class MonitorStreamHealthJobTest extends TestCase
{
    // use RefreshDatabase;

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

        // Mock static call to ProxyService::getStreamSettings
        // Ensure this mocking strategy is robust. Using 'overload' if ProxyService is a concrete class.
        if (!Mockery::getContainer() || !Mockery::getContainer()->hasDefinition(ProxyService::class)) {
             Mockery::mock('overload:' . ProxyService::class)
                ->shouldReceive('getStreamSettings')
                ->zeroOrMoreTimes()
                ->andReturn(['ffmpeg_hls_time' => 4]);
        } else {
            // If already mocked (e.g. by a base test class or another test's setup), re-apply or add expectations
            ProxyService::shouldReceive('getStreamSettings')
                ->zeroOrMoreTimes()
                ->andReturn(['ffmpeg_hls_time' => 4]);
        }

        Storage::fake('app');
        Queue::fake();

        // Use partialMock to allow some Facade methods to pass through if not explicitly mocked
        Cache::partialMock();
        Redis::partialMock();
        Log::partialMock();
        File::partialMock(); // For File::exists, File::glob, File::lastModified used in the Job
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

    #[Test]
    public function handle_terminates_if_monitoring_disabled_flag_is_set()
    {
        Log::shouldReceive('info')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Monitoring disabled. Job terminating.');
        }));
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(true);

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);

        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function handle_pid_not_in_cache_triggers_sequence_failure()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(null);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'PID not found in cache');
        }));

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        Channel::shouldReceive('with')->with('playlist')->zeroOrMoreTimes()->andReturnSelf();
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(103)->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', Mockery::on(function($arg) { return $arg instanceof Channel && $arg->id === 102; }),
                   'Test Channel Original', [101, 102, 103], 1, 100, 2)
            ->andReturn(null);

        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Source model channel ID 103 not found. Skipping.');
        }));

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);

        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function handle_pid_not_running_path_triggers_sequence_failure()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'PID 12345 is not running');
        }));
        // Note: Direct test of posix_kill is hard. This test assumes the condition is met internally.

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        Channel::shouldReceive('with')->with('playlist')->zeroOrMoreTimes()->andReturnSelf();
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(103)->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')->once()->andReturn(null);

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);

        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    // [TODO: Implement further tests as per the testing plan]

    #[Test]
    public function handle_pid_not_ffmpeg_triggers_sequence_failure()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        // This test assumes function_exists('posix_kill') is true and posix_kill(12345, 0) is true.
        // We then mock isFfmpeg to return false.
        $this->hlsStreamServiceMock->shouldReceive('isFfmpeg')->with(12345)->andReturn(false);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'PID 12345 is running but is not an FFmpeg process.');
        }));

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        // Mock subsequent failover attempts to fail quickly
        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        Channel::shouldReceive('with')->with('playlist')->zeroOrMoreTimes()->andReturnSelf();
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(103)->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')->once()->andReturn(null);

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);

        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function handle_segments_current_is_healthy_and_redispatches()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        $this->hlsStreamServiceMock->shouldReceive('isFfmpeg')->with(12345)->andReturn(true); // PID is good

        $hlsDirectoryPath = Storage::disk('app')->path("hls/101");
        File::shouldReceive('exists')->with($hlsDirectoryPath)->andReturn(true);
        File::shouldReceive('glob')->with($hlsDirectoryPath . '/*.ts')->andReturn(['segment3.ts', 'segment1.ts', 'segment2.ts']);
        File::shouldReceive('lastModified')->with('segment1.ts')->andReturn(time() - 1);
        File::shouldReceive('lastModified')->with('segment2.ts')->andReturn(time() - 2);
        File::shouldReceive('lastModified')->with('segment3.ts')->andReturn(time() - 0); // Most recent

        Log::shouldReceive('info')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Stream is healthy.');
        }));
        Log::shouldReceive('debug')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Re-dispatching self');
        }));

        $jobData = [
            'streamType' => 'channel', 'activeStreamId' => 101, 'originalModelId' => 100,
            'originalModelTitle' => 'Test Channel Original', 'playlistIdOfActiveStream' => 1,
            'streamSourceIds' => [101, 102, 103], 'currentIndexInSourceIds' => 0,
        ];
        $job = $this->createJobInstance($jobData);
        $job->handle($this->hlsStreamServiceMock);

        Queue::assertPushed(MonitorStreamHealthJob::class, function ($pushedJob) use ($jobData) {
            return $pushedJob->streamType === $jobData['streamType'] &&
                   $pushedJob->activeStreamId === $jobData['activeStreamId'] &&
                   $pushedJob->originalModelId === $jobData['originalModelId'] &&
                   $pushedJob->originalModelTitle === $jobData['originalModelTitle'] &&
                   $pushedJob->playlistIdOfActiveStream === $jobData['playlistIdOfActiveStream'] &&
                   $pushedJob->streamSourceIds === $jobData['streamSourceIds'] &&
                   $pushedJob->currentIndexInSourceIds === $jobData['currentIndexInSourceIds'];
        });
    }

    #[Test]
    public function handle_no_segments_after_grace_period_triggers_sequence_failure()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        $this->hlsStreamServiceMock->shouldReceive('isFfmpeg')->with(12345)->andReturn(true);

        $hlsDirectoryPath = Storage::disk('app')->path("hls/101");
        File::shouldReceive('exists')->with($hlsDirectoryPath)->andReturn(true);
        File::shouldReceive('glob')->with($hlsDirectoryPath . '/*.ts')->andReturn([]); // No segments

        Redis::shouldReceive('get')->with("hls:streaminfo:starttime:channel:101")->andReturn(time() - 50); // Started 50s ago, grace is 20s

        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'No .ts segments found') && str_contains($message, 'after grace period');
        }));

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);
        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;
        Channel::shouldReceive('with')->with('playlist')->zeroOrMoreTimes()->andReturnSelf();
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(103)->andReturn(null);
        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')->once()->andReturn(null);

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function handle_no_segments_within_grace_period_is_healthy_and_redispatches()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        $this->hlsStreamServiceMock->shouldReceive('isFfmpeg')->with(12345)->andReturn(true);

        $hlsDirectoryPath = Storage::disk('app')->path("hls/101");
        File::shouldReceive('exists')->with($hlsDirectoryPath)->andReturn(true);
        File::shouldReceive('glob')->with($hlsDirectoryPath . '/*.ts')->andReturn([]); // No segments

        Redis::shouldReceive('get')->with("hls:streaminfo:starttime:channel:101")->andReturn(time() - 5); // Started 5s ago, grace is 20s

        Log::shouldReceive('debug')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'No .ts segments yet') && str_contains($message, 'within grace period');
        }));

        $jobData = ['activeStreamId' => 101];
        $job = $this->createJobInstance($jobData);
        $job->handle($this->hlsStreamServiceMock);
        Queue::assertPushed(MonitorStreamHealthJob::class, function ($pushedJob) use ($jobData) {
            return $pushedJob->activeStreamId === $jobData['activeStreamId'];
        });
    }

    #[Test]
    public function handle_stale_segments_triggers_sequence_failure()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(12345);
        $this->hlsStreamServiceMock->shouldReceive('isFfmpeg')->with(12345)->andReturn(true);

        $hlsDirectoryPath = Storage::disk('app')->path("hls/101");
        File::shouldReceive('exists')->with($hlsDirectoryPath)->andReturn(true);
        File::shouldReceive('glob')->with($hlsDirectoryPath . '/*.ts')->andReturn(['segment.ts']);
        File::shouldReceive('lastModified')->with('segment.ts')->andReturn(time() - 50); // 50s old, threshold is 12s

        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Latest segment') && str_contains($message, 'is too old');
        }));

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);
        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;
        Channel::shouldReceive('with')->with('playlist')->zeroOrMoreTimes()->andReturnSelf();
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->zeroOrMoreTimes()->with(103)->andReturn(null);
        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')->once()->andReturn(null);

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    // [TODO: Implement further tests as per the testing plan]

    #[Test]
    public function handleStreamSequenceFailure_successfully_fails_over_to_next_source()
    {
        // This test focuses on the handleStreamSequenceFailure method's logic
        // We'll trigger it by setting up a "PID not in cache" scenario
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(null); // Triggers handleStreamSequenceFailure

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        // Next source to try is 102 (index 1)
        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        Channel::shouldReceive('with')->with('playlist')->once()->andReturnSelf();
        Channel::shouldReceive('find')->once()->with(102)->andReturn($channelMock102);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', $channelMock102, 'Test Channel Original', [101, 102, 103], 1, 100, 2)
            ->andReturn($channelMock102);

        Log::shouldReceive('info')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Successfully failed over to new stream: channel ID 102');
        }));

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);

    }

    #[Test]
    public function handleStreamSequenceFailure_tries_next_source_if_first_failover_attempt_fails()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        $playlistMock3 = Mockery::mock(Playlist::class);
        $playlistMock3->id = 3;
        $channelMock103 = Mockery::mock(Channel::class);
        $channelMock103->id = 103;
        $channelMock103->playlist = $playlistMock3;

        Channel::shouldReceive('with')->with('playlist')->twice()->andReturnSelf();
        Channel::shouldReceive('find')->once()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->once()->with(103)->andReturn($channelMock103);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', $channelMock102, 'Test Channel Original', [101, 102, 103], 1, 100, 2)
            ->andReturn(null);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Attempt to start source channel ID 102 failed.');
        }));

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', $channelMock103, 'Test Channel Original', [101, 102, 103], 2, 100, 3)
            ->andReturn($channelMock103);
        Log::shouldReceive('info')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Successfully failed over to new stream: channel ID 103');
        }));

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);
    }

    #[Test]
    public function handleStreamSequenceFailure_terminates_if_all_remaining_sources_fail()
    {
        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:101")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:101")->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 101);

        $playlistMock2 = Mockery::mock(Playlist::class);
        $playlistMock2->id = 2;
        $channelMock102 = Mockery::mock(Channel::class);
        $channelMock102->id = 102;
        $channelMock102->playlist = $playlistMock2;

        $playlistMock3 = Mockery::mock(Playlist::class);
        $playlistMock3->id = 3;
        $channelMock103 = Mockery::mock(Channel::class);
        $channelMock103->id = 103;
        $channelMock103->playlist = $playlistMock3;

        Channel::shouldReceive('with')->with('playlist')->twice()->andReturnSelf();
        Channel::shouldReceive('find')->once()->with(102)->andReturn($channelMock102);
        Channel::shouldReceive('find')->once()->with(103)->andReturn($channelMock103);

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', $channelMock102, 'Test Channel Original', [101, 102, 103], 1, 100, 2)
            ->andReturn(null);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Attempt to start source channel ID 102 failed.');
        }));

        $this->hlsStreamServiceMock->shouldReceive('attemptSpecificStreamSource')
            ->once()
            ->with('channel', $channelMock103, 'Test Channel Original', [101, 102, 103], 2, 100, 3)
            ->andReturn(null);
        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Attempt to start source channel ID 103 failed.');
        }));

        Log::shouldReceive('error')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'All available sources in the sequence have been attempted and failed');
        }));

        $job = $this->createJobInstance();
        $job->handle($this->hlsStreamServiceMock);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function handleStreamSequenceFailure_when_last_stream_in_sequence_fails()
    {
        $jobParams = [
            'activeStreamId' => 103,
            'currentIndexInSourceIds' => 2,
            'streamSourceIds' => [101, 102, 103],
            'playlistIdOfActiveStream' => 3
        ];
        $job = $this->createJobInstance($jobParams);

        Cache::shouldReceive('get')->with("hls:monitoring_disabled:channel:103")->andReturn(false);
        Cache::shouldReceive('get')->with("hls:pid:channel:103")->andReturn(null);

        $this->hlsStreamServiceMock->shouldReceive('stopStream')->once()->with('channel', 103);

        $this->hlsStreamServiceMock->shouldNotReceive('attemptSpecificStreamSource');

        Log::shouldReceive('error')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'All available sources in the sequence have been attempted and failed');
        }));

        $job = $this->createJobInstance($jobParams); // Re-instantiate job with specific params for this test case.
        $job->handle($this->hlsStreamServiceMock);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    // [TODO: Implement further tests as per the testing plan]
}
