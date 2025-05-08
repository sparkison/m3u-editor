<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'app:hls-prune {--threshold=10}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently';

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
                $pidKey = "hls:pid:{$channelId}";
                $pid = Cache::get($pidKey);

                if ($pid && posix_kill($pid, 0)) {
                    $this->info("🛑 Stopping PID {$pid} for channel {$channelId}");
                    posix_kill($pid, SIGTERM);
                    sleep(1);
                    if (posix_kill($pid, 0)) {
                        posix_kill($pid, SIGKILL);
                    }
                } else {
                    $this->info("❌ No running PID for channel {$channelId}");
                }

                // Cleanup on-disk HLS files
                File::deleteDirectory(storage_path("app/hls/{$channelId}"));
                $this->info("🧹 Deleted files for channel {$channelId}");

                // Remove its Redis entries
                Cache::forget($pidKey);
                Redis::del("hls:last_seen:{$channelId}");
                Redis::srem('hls:active_ids', $channelId);
                $this->info("✂️ Pruned channel {$channelId}");
            } else {
                $this->info("✅ Channel {$channelId} is still active");
            }
        }
    }
}
