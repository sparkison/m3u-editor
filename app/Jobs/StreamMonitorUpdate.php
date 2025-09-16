<?php

namespace App\Jobs;

use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Background job for monitoring stream health and updating statistics
 * 
 * This job runs frequently to:
 * - Monitor stream health and bandwidth
 * - Update real-time statistics
 * - Detect and handle stream failures
 */
class StreamMonitorUpdate implements ShouldQueue
{
    use Queueable;

    public $timeout = 60; // 1 minute
    public $tries = 1;

    public function __construct()
    {
        // This job will be dispatched every minute by the scheduler
    }

    /**
     * Execute the job.
     */
    public function handle(
        SharedStreamService $sharedStreamService,
        StreamMonitorService $monitorService
    ): void {
        try {
            // Update monitoring statistics
            $monitorService->updateSystemStats();

            // Get current stream status
            $systemStats = $monitorService->getSystemStats();

            // Update stream health for all active streams
            $activeStreams = $sharedStreamService->getAllActiveStreams();
            $unhealthyStreams = 0;
            $totalBandwidth = 0;
            foreach ($activeStreams as $streamKey => $streamData) {
                try {
                    // Check stream health
                    $health = $monitorService->checkStreamHealth($streamKey);
                    if (!$health['healthy']) {
                        $unhealthyStreams++;
                        Log::channel('ffmpeg')->warning("StreamMonitor: Unhealthy stream detected: {$streamKey} - {$health['reason']}");

                        // Attempt to cleanup if it's a critical failure
                        // Failover attempts are handled in the SharedStreamService
                        if ($health['critical']) {
                            Log::channel('ffmpeg')->error("StreamMonitor: Critical failure for stream {$streamKey}");
                            $sharedStreamService->cleanupStream($streamKey);
                        }
                    }

                    // Update bandwidth tracking
                    if (isset($health['bandwidth'])) {
                        $totalBandwidth += $health['bandwidth'];
                    }
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("StreamMonitor: Error checking health for stream {$streamKey}: " . $e->getMessage());
                }
            }

            // Update system-wide statistics
            $monitorService->updateGlobalStats([
                'total_streams' => count($activeStreams),
                'unhealthy_streams' => $unhealthyStreams,
                'total_bandwidth' => $totalBandwidth,
                'system_load' => $systemStats['load_average']['1min'] ?? 0,
                'memory_usage_percentage' => $systemStats['memory_usage']['percentage'] ?? 0,
                'redis_connected' => $systemStats['redis_connected'] ?? false,
                'timestamp' => time()
            ]);

            // Clean up stale monitoring data
            $monitorService->cleanupStaleData();

            Log::channel('ffmpeg')->debug(
                "StreamMonitor: Updated stats - " . count($activeStreams) . " streams, " .
                    "{$unhealthyStreams} unhealthy, " . round($totalBandwidth / 1024 / 1024, 2) . "MB/s bandwidth"
            );
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('StreamMonitor: Error during monitoring update: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error('StreamMonitor: Monitoring job failed: ' . $exception->getMessage());
    }
}
