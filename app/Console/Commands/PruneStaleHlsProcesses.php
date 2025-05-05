<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'hls:prune {--threshold=30}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently';

    public function handle()
    {
        $threshold = (int) $this->option('threshold'); // seconds
        $now = now();

        // Fetch active IDs via tag
        $activeIds = Cache::tags('hls_active')->keys();

        foreach ($activeIds as $channelId) {
            $lastSeen = Cache::get("hls:last_seen:{$channelId}");

            if (! $lastSeen || $now->diffInSeconds($lastSeen) > $threshold) {
                $pidKey = "hls:pid:{$channelId}";
                $pid    = Cache::pull($pidKey);

                if ($pid && posix_kill($pid, 0)) {
                    posix_kill($pid, SIGTERM);
                    sleep(1);
                    if (posix_kill($pid, 0)) {
                        posix_kill($pid, SIGKILL);
                    }
                }

                // Cleanup
                File::deleteDirectory(
                    storage_path("app/hls/{$channelId}")
                );

                // Remove from active set
                Cache::tags('hls_active')->forget($channelId);
                Cache::forget("hls:last_seen:{$channelId}");
            }
        }
    }
}
