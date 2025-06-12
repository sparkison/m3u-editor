<?php

namespace Tests\Unit\Services;

use App\Services\HlsStreamService;
use App\Services\ProxyService;
use App\Jobs\MonitorStreamHealthJob;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Traits\TracksActiveStreams;
use App\Exceptions\SourceNotResponding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test; // Added import for Test attribute
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamServiceTest extends TestCase
{
    // use RefreshDatabase;

    protected $proxyServiceMock;
    // HlsStreamService instance will be created directly, but methods might be mocked if testing other methods.
    // For testing a specific method like attemptSpecificStreamSource, we might spy on the service or use a real instance
    // and mock its internal calls like runPreCheck, startStreamWithSpeedCheck.


    protected function setUp(): void
    {
        parent::setUp();

        // Common Mocks & Configs
        Config::set('streaming.monitor_job_interval_seconds', 10);

        // Mock ProxyService::getStreamSettings() as it's used by HlsStreamService
        // Ensure App\Services\ProxyService is imported via 'use App\Services\ProxyService;'
        Mockery::mock('overload:'.ProxyService::class)
            ->shouldReceive('getStreamSettings')
            ->zeroOrMoreTimes()
            ->andReturn([
                'ffmpeg_ffprobe_timeout' => 5,
                'ffmpeg_hls_time' => 4
                // Add other settings HlsStreamService::buildCmd might need, if necessary for these unit tests
            ]);

        Queue::fake();
        Storage::fake('app'); // For HLS file operations, if any are directly in methods being tested

        Config::set('logging.default', 'null');

        Cache::partialMock();
        Redis::partialMock();
        Log::partialMock();
        File::partialMock(); // If HlsStreamService uses File facade directly

        // Mock the TracksActiveStreams trait methods if they are called and need specific behavior
        // This is more complex. For now, assume they work or are not critical for specific method unit tests.
        // Alternatively, use a partial mock of HlsStreamService if testing a method that calls another public method on self.
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // Helper to create a mock model (Channel or Episode) with a playlist
    protected function mockStreamModel(string $type, int $id, int $playlistId, int $availableStreams = 1): Mockery\MockInterface
    {
        $modelMock = null;
        $playlistMock = Mockery::mock(Playlist::class)->makePartial();
        $playlistMock->id = $playlistId;
        $playlistMock->available_streams = $availableStreams;
        $playlistMock->user_agent = 'Test User Agent';

        if ($type === 'channel') {
            $modelMock = Mockery::mock(\App\Models\Channel::class)->makePartial();
            $modelMock->title_custom = "Test Custom Title {$id}";
            $modelMock->title = "Test Title {$id}";
            $modelMock->url_custom = "http://custom.test/{$id}";
            $modelMock->url = "http://test/{$id}";
        } else { // episode
            $modelMock = Mockery::mock(Episode::class)->makePartial();
            $modelMock->title = "Test Episode Title {$id}";
            $modelMock->url = "http://episode.test/{$id}";
        }
        $modelMock->id = $id;
        $modelMock->playlist = $playlistMock;

        return $modelMock;
    }

    // Test methods for attemptSpecificStreamSource will be added here.
    // Test methods for startStream (job dispatch part) will be added here.

    #[Test]
    public function attemptSpecificStreamSource_starts_stream_and_dispatches_job_on_success()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $type = 'channel';
        $channelMock = $this->mockStreamModel($type, 201, 5);
        $originalModelId = 100;
        $originalModelTitle = 'Original Title';
        $streamSourceIds = [100, 201, 300];
        $newCurrentIndexInSourceIds = 1;
        $playlistIdOfSpecificStream = 5;

        $serviceSpy->shouldReceive('incrementActiveStreams')->with($playlistIdOfSpecificStream)->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')->andReturnNull();
        $serviceSpy->shouldReceive('startStreamWithSpeedCheck')->andReturn(12345);

        Cache::shouldReceive('forget')->with("hls:monitoring_disabled:{$type}:201")->once();

        $result = $serviceSpy->attemptSpecificStreamSource(
            $type, $channelMock, $originalModelTitle, $streamSourceIds,
            $newCurrentIndexInSourceIds, $originalModelId, $playlistIdOfSpecificStream
        );

        $this->assertSame($channelMock, $result);
        Queue::assertPushed(MonitorStreamHealthJob::class, function ($job) use ($type, $channelMock, $originalModelId, $originalModelTitle, $streamSourceIds, $newCurrentIndexInSourceIds, $playlistIdOfSpecificStream) {
            return $job->streamType === $type &&
                   $job->activeStreamId === $channelMock->id &&
                   $job->originalModelId === $originalModelId &&
                   $job->originalModelTitle === $originalModelTitle &&
                   $job->playlistIdOfActiveStream === $playlistIdOfSpecificStream &&
                   $job->streamSourceIds === $streamSourceIds &&
                   $job->currentIndexInSourceIds === $newCurrentIndexInSourceIds;
        });
    }

    #[Test]
    public function attemptSpecificStreamSource_returns_null_if_stream_limit_reached()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $channelMock = $this->mockStreamModel('channel', 201, 5, 1); // Max 1 stream
        $playlistId = 5;

        $serviceSpy->shouldReceive('incrementActiveStreams')->with($playlistId)->andReturn(2); // Returns 2, meaning 2 are now active
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->with($playlistId, 1, 2)->andReturn(true); // Limit is 1, active is 2
        $serviceSpy->shouldReceive('decrementActiveStreams')->with($playlistId)->once();

        Log::shouldReceive('warning')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Max streams reached for playlist ID 5');
        }));

        $result = $serviceSpy->attemptSpecificStreamSource(
            'channel', $channelMock, 'Title', [], 0, 100, $playlistId
        );

        $this->assertNull($result);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function attemptSpecificStreamSource_returns_null_on_SourceNotResponding_exception()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $channelMock = $this->mockStreamModel('channel', 201, 5);
        $playlistId = 5;

        $serviceSpy->shouldReceive('incrementActiveStreams')->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')->andThrow(new SourceNotResponding('FFprobe failed'));
        $serviceSpy->shouldReceive('decrementActiveStreams')->with($playlistId)->once();

        Log::shouldReceive('error')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Source not responding for specific source');
        }));

        $result = $serviceSpy->attemptSpecificStreamSource(
            'channel', $channelMock, 'Title', [], 0, 100, $playlistId
        );
        $this->assertNull($result);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function attemptSpecificStreamSource_returns_null_on_general_exception_during_start()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $channelMock = $this->mockStreamModel('channel', 201, 5);
        $playlistId = 5;

        $serviceSpy->shouldReceive('incrementActiveStreams')->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')->andReturnNull(); // PreCheck Ok
        $serviceSpy->shouldReceive('startStreamWithSpeedCheck')->andThrow(new \Exception('FFmpeg process error'));
        $serviceSpy->shouldReceive('decrementActiveStreams')->with($playlistId)->once();

        Log::shouldReceive('error')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Error streaming specific source');
        }));

        $result = $serviceSpy->attemptSpecificStreamSource(
            'channel', $channelMock, 'Title', [], 0, 100, $playlistId
        );
        $this->assertNull($result);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }


    #[Test]
    public function startStream_compiles_sources_and_dispatches_job_on_first_source_success()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);

        $originalModelPlaylistMock = Mockery::mock(Playlist::class)->makePartial();
        $originalModelPlaylistMock->id = 1;
        $originalModelPlaylistMock->available_streams = 2;
        $originalModelPlaylistMock->user_agent = 'Test UA';

        $originalModelMock = Mockery::mock(Channel::class)->makePartial();
        $originalModelMock->id = 100;
        $originalModelMock->failoverChannels = collect([]);
        $originalModelMock->playlist = $originalModelPlaylistMock;
        $originalModelMock->shouldReceive('getAttribute')->with('id')->andReturn(100);
        $originalModelMock->shouldReceive('getAttribute')->with('failoverChannels')->andReturn(collect([]));
        $originalModelMock->shouldReceive('getAttribute')->with('playlist')->andReturn($originalModelPlaylistMock);
        $originalModelMock->title_custom = null;
        $originalModelMock->title = 'Original Channel Title';
        $originalModelMock->url_custom = null;
        $originalModelMock->url = 'http://primary.url';


        $serviceSpy->shouldReceive('isRunning')->andReturn(false);

        Channel::shouldReceive('with')->with('playlist')->andReturnSelf();
        Channel::shouldReceive('find')->with(100)->andReturn($originalModelMock);

        $serviceSpy->shouldReceive('incrementActiveStreams')->with(1)->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->with(1, 2, 1)->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')->andReturnNull();
        $serviceSpy->shouldReceive('startStreamWithSpeedCheck')->andReturn(54321); // PID

        Cache::shouldReceive('forget')->with("hls:monitoring_disabled:channel:100")->once();

        $type = 'channel';
        $title = 'Original Channel Title';

        $result = $serviceSpy->startStream($type, $originalModelMock, $title);

        $this->assertSame($originalModelMock, $result);

        $expectedStreamSourceIds = [100];
        $expectedCurrentIndex = 0;

        Queue::assertPushed(MonitorStreamHealthJob::class, function ($job) use ($type, $originalModelMock, $title, $expectedStreamSourceIds, $expectedCurrentIndex) {
            return $job->streamType === $type &&
                   $job->activeStreamId === $originalModelMock->id &&
                   $job->originalModelId === $originalModelMock->id &&
                   $job->originalModelTitle === $title &&
                   $job->playlistIdOfActiveStream === $originalModelMock->playlist->id &&
                   $job->streamSourceIds === $expectedStreamSourceIds &&
                   $job->currentIndexInSourceIds === $expectedCurrentIndex;
        });
    }

    #[Test]
    public function startStream_uses_failover_and_dispatches_job_with_correct_index()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);

        $primaryPlaylistMock = Mockery::mock(Playlist::class)->makePartial(); $primaryPlaylistMock->id = 1; $primaryPlaylistMock->available_streams = 1; $primaryPlaylistMock->user_agent = 'UA1';
        $failoverPlaylistMock = Mockery::mock(Playlist::class)->makePartial(); $failoverPlaylistMock->id = 2; $failoverPlaylistMock->available_streams = 1; $failoverPlaylistMock->user_agent = 'UA2';

        $failoverChannelMock = Mockery::mock(Channel::class)->makePartial();
        $failoverChannelMock->id = 102;
        $failoverChannelMock->playlist = $failoverPlaylistMock;
        $failoverChannelMock->title_custom = null; $failoverChannelMock->title = 'Failover Channel'; $failoverChannelMock->url_custom = null; $failoverChannelMock->url = 'http://failover.url';

        $originalModelMock = Mockery::mock(Channel::class)->makePartial();
        $originalModelMock->id = 100;
        $originalModelMock->failoverChannels = collect([$failoverChannelMock]);
        $originalModelMock->playlist = $primaryPlaylistMock;
        $originalModelMock->shouldReceive('getAttribute')->with('id')->andReturn(100);
        $originalModelMock->shouldReceive('getAttribute')->with('failoverChannels')->andReturn(collect([$failoverChannelMock]));
        $originalModelMock->shouldReceive('getAttribute')->with('playlist')->andReturn($primaryPlaylistMock);
        $originalModelMock->title_custom = null; $originalModelMock->title = 'Primary Channel'; $originalModelMock->url_custom = null; $originalModelMock->url = 'http://primary.url';

        $serviceSpy->shouldReceive('isRunning')->andReturn(false);

        Channel::shouldReceive('with')->with('playlist')->andReturnSelf();
        Channel::shouldReceive('find')->with(100)->andReturn($originalModelMock);
        Channel::shouldReceive('find')->with(102)->andReturn($failoverChannelMock);

        $serviceSpy->shouldReceive('incrementActiveStreams')->with(1)->once()->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->with(1, 1, 1)->once()->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')
            ->with('channel', 100, 'http://primary.url', 'UA1', 'Primary Channel', Mockery::any())
            ->once()
            ->andThrow(new SourceNotResponding('Primary failed precheck'));
        $serviceSpy->shouldReceive('decrementActiveStreams')->with(1)->once();

        $serviceSpy->shouldReceive('incrementActiveStreams')->with(2)->once()->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->with(2, 1, 1)->once()->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')
            ->with('channel', 102, 'http://failover.url', 'UA2', 'Failover Channel', Mockery::any())
            ->once()
            ->andReturnNull();
        $serviceSpy->shouldReceive('startStreamWithSpeedCheck')
             ->with('channel', $failoverChannelMock, 'http://failover.url', 'Failover Channel', 2, 'UA2')
            ->once()
            ->andReturn(54322); // PID

        Cache::shouldReceive('forget')->with("hls:monitoring_disabled:channel:102")->once();

        $type = 'channel'; $title = 'Primary Channel';
        $result = $serviceSpy->startStream($type, $originalModelMock, $title);

        $this->assertSame($failoverChannelMock, $result);
        $expectedStreamSourceIds = [100, 102];
        $expectedCurrentIndex = 1;

        Queue::assertPushed(MonitorStreamHealthJob::class, function ($job) use ($type, $originalModelMock, $title, $failoverChannelMock, $expectedStreamSourceIds, $expectedCurrentIndex) {
            return $job->streamType === $type &&
                   $job->activeStreamId === $failoverChannelMock->id &&
                   $job->originalModelId === $originalModelMock->id &&
                   $job->originalModelTitle === $title &&
                   $job->playlistIdOfActiveStream === $failoverChannelMock->playlist->id &&
                   $job->streamSourceIds === $expectedStreamSourceIds &&
                   $job->currentIndexInSourceIds === $expectedCurrentIndex;
        });
    }

    #[Test]
    public function startStream_returns_null_if_all_sources_fail()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $originalModelMock = $this->mockStreamModel('channel', 100, 1);
        $originalModelMock->failoverChannels = collect([]);
        $originalModelMock->shouldReceive('getAttribute')->with('id')->andReturn(100);
        $originalModelMock->shouldReceive('getAttribute')->with('failoverChannels')->andReturn(collect([]));

        $serviceSpy->shouldReceive('isRunning')->andReturn(false);
        Channel::shouldReceive('with')->with('playlist')->andReturnSelf();
        Channel::shouldReceive('find')->with(100)->andReturn($originalModelMock);

        $serviceSpy->shouldReceive('incrementActiveStreams')->andReturn(1);
        $serviceSpy->shouldReceive('wouldExceedStreamLimit')->andReturn(false);
        $serviceSpy->shouldReceive('runPreCheck')->andThrow(new SourceNotResponding('Failed'));
        $serviceSpy->shouldReceive('decrementActiveStreams')->with(1)->once();

        Log::shouldReceive('error')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'No available HLS streams for channel');
        }));

        $result = $serviceSpy->startStream('channel', $originalModelMock, 'Original Title');

        $this->assertNull($result);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
    }

    #[Test]
    public function startStream_returns_existing_running_stream_and_does_not_dispatch_job()
    {
        $serviceSpy = Mockery::spy(HlsStreamService::class)->makePartial()->shouldAllowMockingProtectedMethods(true);
        $originalModelMock = $this->mockStreamModel('channel', 100, 1);
        $originalModelMock->failoverChannels = collect([]);
        $originalModelMock->shouldReceive('getAttribute')->with('id')->andReturn(100);
         $originalModelMock->shouldReceive('getAttribute')->with('failoverChannels')->andReturn(collect([]));


        $serviceSpy->shouldReceive('isRunning')->with('channel', 100)->andReturn(true);

        Log::shouldReceive('debug')->once()->with(Mockery::on(function($message) {
            return str_contains($message, 'Found existing running stream');
        }));

        $result = $serviceSpy->startStream('channel', $originalModelMock, 'Original Title');

        $this->assertSame($originalModelMock, $result);
        Queue::assertNotPushed(MonitorStreamHealthJob::class);
        $serviceSpy->shouldNotHaveReceived('incrementActiveStreams');
    }

}
