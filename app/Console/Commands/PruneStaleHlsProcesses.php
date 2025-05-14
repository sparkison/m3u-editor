<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Services\HlsStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'app:hls-prune {--threshold=10}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently';

    private $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
        parent::__construct();
    }

    public function handle()
    {
        // Get the threshold from the command line option (default is 10 seconds)
        $threshold = (int)$this->option('threshold');

        // Fetch the list of active channel IDs from Redis
        $activeIds = Redis::smembers('hls:active_ids');
        $this->info("Found " . count($activeIds) . " active channel IDs");

        // For each active channel, check staleness
        foreach ($activeIds as $channelId) {
            $this->info("Checking channel {$channelId}");
            $ts = Redis::get("hls:last_seen:{$channelId}");
            if (! $ts) {
                $this->info("⏰ No last-seen timestamp for {$channelId}");
                continue;
            }
            $lastSeen = Carbon::createFromTimestamp((int) $ts);
            if ($lastSeen->addSeconds($threshold)->isPast()) {
                $wasRunning = $this->hlsService->stopStream($channelId);
                if (!$wasRunning) {
                    $this->info("❌ Channel {$channelId} was not running, skipping");
                    continue;
                } else {
                    $this->info("❌ Channel {$channelId} was running and has been stopped");
                }
            } else {
                $this->info("✅ Channel {$channelId} is still active");
            }
        }
    }
}
