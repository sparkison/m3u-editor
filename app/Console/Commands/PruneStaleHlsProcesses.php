<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'hls:prune {--threshold=30}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently';

    public function handle()
    {
        $threshold = (int) $this->option('threshold'); // seconds
        $now = now();

        // Scan Redis for all hls:last_seen:{channelId} keys
        $cursor = null;
        $pattern = 'hls:last_seen:*';
        $lastSeenKeys = [];

        do {
            list($cursor, $matches) = Redis::scan($cursor, [
                'match' => $pattern,
                'count' => 100,
            ]);
            $lastSeenKeys = array_merge($lastSeenKeys, $matches);
        } while ($cursor != 0);

        // For each channel that has a last-seen key, decide if it’s stale
        foreach ($lastSeenKeys as $key) {
            // key = "hls:last_seen:{channelId}"
            $channelId = basename(str_replace('hls:last_seen', '', $key), ':');

            $lastSeen = Cache::get($key);
            if (! $lastSeen || $now->diffInSeconds($lastSeen) > $threshold) {
                // Stop the FFmpeg process
                $pidKey = "hls:pid:{$channelId}";
                $pid = Cache::pull($pidKey);

                if ($pid && posix_kill($pid, 0)) {
                    // graceful
                    posix_kill($pid, SIGTERM);
                    sleep(1);
                    // force if still alive
                    if (posix_kill($pid, 0)) {
                        posix_kill($pid, SIGKILL);
                    }
                    $this->info("Pruned HLS for channel {$channelId}, killed PID {$pid}");
                }

                // Cleanup HLS files
                File::deleteDirectory(storage_path("app/hls/{$channelId}"));

                // Remove stale cache entries
                Cache::forget($key);
            }
        }
    }
}
