<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\XtreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateXtreamStats implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist|PlaylistAlias $playlist
    ) {}

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware()
    {
        // Prevent overlapping jobs for the same playlist
        return [new WithoutOverlapping($this->playlist->id)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Determine playlist type and cache key
        $playlist = $this->playlist;
        $id = $playlist->id;
        $type = $playlist instanceof Playlist ? 'playlist' : 'playlist_alias';
        $key = "{$type}:{$id}:xtream_status";
        $results = [];

        // Update cache to avoid immediate re-fetch
        $value = $playlist->getRawOriginal('xtream_status');
        $value = is_string($value) ? json_decode($value, true) : ($value ?? []);
        Cache::put($key, $value, 5);

        // Fetch fresh data from Xtream API based on playlist type
        if ($type === 'playlist') {
            if ($playlist->xtream) {
                try {
                    $xtream = XtreamService::make(xtream_config: $playlist->xtream_config);
                    if ($xtream) {
                        $userInfo = $xtream->userInfo(timeout: 3);
                        $results = $userInfo ?: [];
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch metadata for Xtream Playlist '.$playlist->id, ['exception' => $e]);
                }
            }
        } else {
            $primaryConfig = $playlist->getPrimaryXtreamConfig();
            if ($primaryConfig) {
                try {
                    $xtream = XtreamService::make(xtream_config: $primaryConfig);
                    if ($xtream) {
                        $userInfo = $xtream->userInfo(timeout: 3);
                        $results = $userInfo ?: [];
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch metadata for Xtream PlaylistAlias '.$playlist->id, ['exception' => $e]);
                }
            }
        }

        // If we got fresh results, update database and cache
        if (! empty($results)) {
            // Update the playlist record with fresh results
            $this->playlist->update(['xtream_status' => $results]);

            // Cache the fresh results
            Cache::put($key, $results, 5);
        }
    }
}
