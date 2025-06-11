<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
use App\Services\ProxyService; // For getStreamSettings -> hls_time
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis; // For stream start time
use Illuminate\Support\Facades\Storage;

class MonitorStreamHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $streamType;
    public int $activeStreamId;
    public int $originalModelId;
    public string $originalModelTitle;
    public int $playlistIdOfActiveStream;
    public array $streamSourceIds;
    public int $currentIndexInSourceIds;

    /**
     * Create a new job instance.
     *
     * @param string $streamType ('channel' or 'episode')
     * @param int $activeStreamId ID of the stream model instance currently being monitored
     * @param int $originalModelId ID of the initially requested Channel or Episode
     * @param string $originalModelTitle Title of the initially requested model
     * @param int $playlistIdOfActiveStream Playlist ID of the active stream
     * @param array $streamSourceIds Ordered array of all possible stream model IDs for the originalModelId
     * @param int $currentIndexInSourceIds Index in streamSourceIds that corresponds to activeStreamId
     */
    public function __construct(
        string $streamType,
        int $activeStreamId,
        int $originalModelId,
        string $originalModelTitle,
        int $playlistIdOfActiveStream,
        array $streamSourceIds,
        int $currentIndexInSourceIds
    ) {
        $this->streamType = $streamType;
        $this->activeStreamId = $activeStreamId;
        $this->originalModelId = $originalModelId;
        $this->originalModelTitle = $originalModelTitle;
        $this->playlistIdOfActiveStream = $playlistIdOfActiveStream;
        $this->streamSourceIds = $streamSourceIds;
        $this->currentIndexInSourceIds = $currentIndexInSourceIds;

        $this->tries = config('streaming.monitor_job_tries', 3);
        $this->backoff = config('streaming.monitor_job_backoff', [60, 120, 300]);
        $this->onQueue(config('proxy.queue_priority_hls_monitor', 'default'));
    }

    /**
     * Execute the job.
     *
     * @param HlsStreamService $hlsStreamService
     * @return void
     */
    public function handle(HlsStreamService $hlsStreamService): void
    {
        // Check for intentional stop
        $monitoringDisabledCacheKey = "hls:monitoring_disabled:{$this->streamType}:{$this->activeStreamId}";
        if (Cache::get($monitoringDisabledCacheKey)) {
            Log::channel('ffmpeg')->info(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Monitoring disabled. Job terminating."
            );
            return;
        }

        // --- 1. PID Check (for $this->activeStreamId) ---
        $pidCacheKey = "hls:pid:{$this->streamType}:{$this->activeStreamId}";
        $pid = Cache::get($pidCacheKey);

        if (!$pid) {
            Log::channel('ffmpeg')->warning(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] PID not found in cache. Stream presumed down."
            );
            $this->handleStreamSequenceFailure($hlsStreamService);
            return;
        }

        if (!function_exists('posix_kill') || !posix_kill($pid, 0)) {
            Log::channel('ffmpeg')->warning(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] PID {$pid} is not running."
            );
            $this->handleStreamSequenceFailure($hlsStreamService);
            return;
        }

        if (!$hlsStreamService->isFfmpeg($pid)) {
            Log::channel('ffmpeg')->warning(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] PID {$pid} is running but is not an FFmpeg process."
            );
            $this->handleStreamSequenceFailure($hlsStreamService);
            return;
        }
        Log::channel('ffmpeg')->debug(
            "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] PID {$pid} is alive and is an FFmpeg process."
        );

        // --- 2. HLS Segment Age Check (for $this->activeStreamId) ---
        $hlsDirectoryPath = $this->getHlsDirectoryPathForStream($this->activeStreamId);

        if (!$hlsDirectoryPath) {
            Log::channel('ffmpeg')->error(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Could not determine HLS directory path."
            );
            $this->handleStreamSequenceFailure($hlsStreamService);
            return;
        }

        if (!File::exists($hlsDirectoryPath)) {
            Log::channel('ffmpeg')->warning(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] HLS directory {$hlsDirectoryPath} does not exist. Assuming stream is down."
            );
            $this->handleStreamSequenceFailure($hlsStreamService);
            return;
        }

        $segments = collect(File::glob($hlsDirectoryPath . '/*.ts'));

        if ($segments->isEmpty()) {
            $streamStartTimeKey = "hls:streaminfo:starttime:{$this->streamType}:{$this->activeStreamId}";
            $startTime = Redis::get($streamStartTimeKey); // Ensure Redis facade is imported
            $gracePeriod = config('streaming.hls_segment_grace_period_seconds', 20);

            if ($startTime && (time() - $startTime > $gracePeriod)) {
                Log::channel('ffmpeg')->warning(
                    "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] No .ts segments found in {$hlsDirectoryPath} after grace period."
                );
                $this->handleStreamSequenceFailure($hlsStreamService);
                return;
            } elseif (!$startTime) {
                Log::channel('ffmpeg')->warning(
                    "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] No .ts segments found and no start time available. Assuming failure."
                );
                $this->handleStreamSequenceFailure($hlsStreamService);
                return;
            } else { // Within grace period
                Log::channel('ffmpeg')->debug(
                    "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] No .ts segments yet, but stream started recently (within grace period). Skipping failure."
                );
                $this->reDispatchHealthyJob();
                return;
            }
        } else { // Segments are present
            $latestSegmentTimestamp = 0;
            foreach ($segments as $segmentFile) {
                $modTime = File::lastModified($segmentFile);
                if ($modTime > $latestSegmentTimestamp) {
                    $latestSegmentTimestamp = $modTime;
                }
            }

            $streamSettings = ProxyService::getStreamSettings();
            $hlsTime = $streamSettings['ffmpeg_hls_time'] ?? 4;
            $ageThresholdSeconds = $hlsTime * config('streaming.hls_segment_age_multiplier', 3);

            if ((time() - $latestSegmentTimestamp) > $ageThresholdSeconds) {
                Log::channel('ffmpeg')->warning(
                    "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Latest segment in {$hlsDirectoryPath} is too old (Timestamp: {$latestSegmentTimestamp})."
                );
                $this->handleStreamSequenceFailure($hlsStreamService);
                return;
            }
            Log::channel('ffmpeg')->debug(
                "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Latest segment timestamp {$latestSegmentTimestamp} is current."
            );
        }

        // If all checks passed:
        Log::channel('ffmpeg')->info(
            "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Stream is healthy."
        );
        $this->reDispatchHealthyJob();
    }

    /**
     * Get the HLS directory path for a given stream ID.
     */
    protected function getHlsDirectoryPathForStream(int $streamId): ?string
    {
        // Ensure Storage facade is imported
        if ($this->streamType === 'episode') {
            return Storage::disk('app')->path("hls/e/{$streamId}");
        } elseif ($this->streamType === 'channel') {
            return Storage::disk('app')->path("hls/{$streamId}");
        }
        Log::channel('ffmpeg')->error("[MonitorHelper] Invalid streamType '{$this->streamType}' encountered when getting HLS directory path for stream ID {$streamId}.");
        return null;
    }

    /**
     * Re-dispatch this job for the next interval if the stream is healthy.
     */
    protected function reDispatchHealthyJob(): void
    {
        $monitoringDisabledCacheKey = "hls:monitoring_disabled:{$this->streamType}:{$this->activeStreamId}";
        if (Cache::get($monitoringDisabledCacheKey)) {
            Log::channel('ffmpeg')->info("[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Monitoring was disabled during job execution. Not re-dispatching.");
            return;
        }

        $delaySeconds = config('streaming.monitor_job_interval_seconds', 10);
        Log::channel('ffmpeg')->debug(
            "[Monitor][{$this->streamType} ID {$this->activeStreamId}, OrigReq ID {$this->originalModelId}] Re-dispatching self with a {$delaySeconds}s delay."
        );

        self::dispatch(
            $this->streamType,
            $this->activeStreamId,
            $this->originalModelId,
            $this->originalModelTitle,
            $this->playlistIdOfActiveStream,
            $this->streamSourceIds,
            $this->currentIndexInSourceIds
        )->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Handles the sequential failover logic when the current active stream fails.
     */
    protected function handleStreamSequenceFailure(HlsStreamService $hlsStreamService): void
    {
        Log::channel('ffmpeg')->warning(
            "[SeqFail][OrigReq ID {$this->originalModelId}] Active stream {$this->streamType} ID {$this->activeStreamId} (index {$this->currentIndexInSourceIds}) failed health check. Initiating failover."
        );

        try {
            Log::channel('ffmpeg')->debug(
                "[SeqFail][OrigReq ID {$this->originalModelId}] Stopping failed stream {$this->streamType} ID {$this->activeStreamId}."
            );
            $hlsStreamService->stopStream($this->streamType, $this->activeStreamId);
            Log::channel('ffmpeg')->info(
                "[SeqFail][OrigReq ID {$this->originalModelId}] Successfully stopped failed stream {$this->streamType} ID {$this->activeStreamId}."
            );
        } catch (Exception $e) {
            Log::channel('ffmpeg')->error(
                "[SeqFail][OrigReq ID {$this->originalModelId}] Exception while stopping failed stream {$this->streamType} ID {$this->activeStreamId}: " . $e->getMessage()
            );
        }

        $nextStreamIndexToAttempt = $this->currentIndexInSourceIds + 1;

        for ($i = $nextStreamIndexToAttempt; $i < count($this->streamSourceIds); $i++) {
            $streamIdToAttempt = $this->streamSourceIds[$i];
            Log::channel('ffmpeg')->info(
                "[SeqFail][OrigReq ID {$this->originalModelId}] Attempting next source: {$this->streamType} ID {$streamIdToAttempt} (index {$i} of " . (count($this->streamSourceIds) - 1) . ")"
            );

            $streamModelToAttempt = null;
            if ($this->streamType === 'channel') {
                $streamModelToAttempt = Channel::with('playlist')->find($streamIdToAttempt);
            } elseif ($this->streamType === 'episode') {
                $streamModelToAttempt = Episode::with('playlist')->find($streamIdToAttempt);
            }

            if (!$streamModelToAttempt) {
                Log::channel('ffmpeg')->warning(
                    "[SeqFail][OrigReq ID {$this->originalModelId}] Source model {$this->streamType} ID {$streamIdToAttempt} not found. Skipping."
                );
                continue;
            }

            if (!$streamModelToAttempt->playlist) {
                 Log::channel('ffmpeg')->warning(
                    "[SeqFail][OrigReq ID {$this->originalModelId}] Source model {$this->streamType} ID {$streamIdToAttempt} has no playlist loaded/defined. Skipping."
                );
                continue;
            }

            try {
                $newlyStartedStream = $hlsStreamService->attemptSpecificStreamSource(
                    $this->streamType,
                    $streamModelToAttempt,
                    $this->originalModelTitle,
                    $this->streamSourceIds,
                    $i, // The index of the stream being attempted
                    $this->originalModelId,
                    $streamModelToAttempt->playlist->id // Pass the playlist_id
                );

                if ($newlyStartedStream) {
                    Log::channel('ffmpeg')->info(
                        "[SeqFail][OrigReq ID {$this->originalModelId}] Successfully failed over to new stream: {$this->streamType} ID {$newlyStartedStream->id} (index {$i}). Monitoring will continue."
                    );
                    return;
                } else {
                    Log::channel('ffmpeg')->warning(
                        "[SeqFail][OrigReq ID {$this->originalModelId}] Attempt to start source {$this->streamType} ID {$streamIdToAttempt} failed."
                    );
                }
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error(
                    "[SeqFail][OrigReq ID {$this->originalModelId}] Exception while attempting to start source {$this->streamType} ID {$streamIdToAttempt}: " . $e->getMessage()
                );
            }
        }

        Log::channel('ffmpeg')->error(
            "[SeqFail][OrigReq ID {$this->originalModelId}] All available sources in the sequence have been attempted and failed after initial failure of stream ID {$this->activeStreamId}."
        );
    }
}
