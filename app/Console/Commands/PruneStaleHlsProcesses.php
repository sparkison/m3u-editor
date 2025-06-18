<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Services\HlsStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'app:hls-prune {--threshold=15}';
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
        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        $activeEspisodeIds = Redis::smembers('hls:active_episode_ids');

        $stoppedChannels = 0;
        $stoppedEpisodes = 0;

        // For each active channel, check staleness
        foreach ($activeChannelIds as $channelId) {
            $ts = Redis::get("hls:channel_last_seen:{$channelId}");
            if (! $ts) {
                continue;
            }
            $lastSeen = Carbon::createFromTimestamp((int) $ts);
            if ($lastSeen->addSeconds($threshold)->isPast()) {
                $wasRunning = $this->hlsService->stopStream(type: 'channel', id: $channelId);
                if ($wasRunning) {
                    $stoppedChannels++;
                    $this->info("🛑 Stopped stale channel {$channelId}");
                }
            }
        }

        // For each active episode, check staleness
        foreach ($activeEspisodeIds as $episodeId) {
            $ts = Redis::get("hls:episode_last_seen:{$episodeId}");
            if (! $ts) {
                continue;
            }
            $lastSeen = Carbon::createFromTimestamp((int) $ts);
            if ($lastSeen->addSeconds($threshold)->isPast()) {
                $wasRunning = $this->hlsService->stopStream(type: 'episode', id: $episodeId);
                if ($wasRunning) {
                    $stoppedEpisodes++;
                    $this->info("🛑 Stopped stale episode {$episodeId}");
                }
            }
        }

        // Only output summary if there was activity
        if ($stoppedChannels > 0 || $stoppedEpisodes > 0) {
            $this->info("HLS Prune: Stopped {$stoppedChannels} channels and {$stoppedEpisodes} episodes");
        }
    }
}
