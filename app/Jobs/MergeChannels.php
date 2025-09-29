<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public int $playlistId,
        public bool $checkResolution = false,
        public bool $disableFallbackChannels = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;

        // Build unified playlist IDs array and create priority lookup
        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? $item['playlist_failover_id'] : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId); // Add preferred playlist at the beginning
        }

        $playlistIds = $playlistIds->unique()->toArray();

        // Create playlist priority lookup for efficient sorting
        $playlistPriority = $playlistIds ? array_flip($playlistIds) : [];

        // Get all channels with stream IDs in a single efficient query
        $allChannels = Channel::where('user_id', $this->user->id)
            ->whereIn('playlist_id', $playlistIds)
            ->where(function ($query) {
                $query->where('stream_id_custom', '!=', '')
                    ->orWhere('stream_id', '!=', '');
            })->cursor();

        // Group channels by stream ID using LazyCollection
        $groupedChannels = $allChannels->groupBy(function ($channel) {
            $streamId = $channel->stream_id_custom ?: $channel->stream_id;
            return strtolower(trim($streamId));
        });

        // Process each group of channels with the same stream ID
        foreach ($groupedChannels as $streamId => $group) {
            if ($group->count() <= 1) {
                continue; // Skip single channels
            }

            // Select master channel based on criteria
            $master = $this->selectMasterChannel($group, $playlistPriority);
            if (!$master) {
                continue; // Skip if no valid master found
            }

            // Create failover relationships for remaining channels
            $failoverChannels = $group->where('id', '!=', $master->id);
            if ($this->checkResolution) {
                $failoverChannels = $failoverChannels->sortByDesc(fn($channel) => $this->getResolution($channel));
            } else {
                $failoverChannels = $failoverChannels->sortBy(fn($channel) => $playlistPriority[$channel->playlist_id] ?? 999);
            }

            // Create failover relationships using updateOrCreate for compatibility
            foreach ($failoverChannels as $failover) {
                ChannelFailover::updateOrCreate(
                    [
                        'channel_id' => $master->id,
                        'channel_failover_id' => $failover->id
                    ],
                    ['user_id' => $this->user->id]
                );
                $processed++;

                // Optionally disable fallback channels if requested
                if ($this->disableFallbackChannels) {
                    $failover->update(['enabled' => false]);
                }
            }
        }

        $this->sendCompletionNotification($processed);
    }

    /**
     * Select the master channel from a group based on priority rules
     */
    private function selectMasterChannel($group, array $playlistPriority)
    {
        if ($this->checkResolution) {
            // Priority 1: Preferred playlist with highest resolution
            if ($this->playlistId) {
                $preferredChannels = $group->where('playlist_id', $this->playlistId);
                if ($preferredChannels->isNotEmpty()) {
                    return $preferredChannels->reduce(function ($highest, $channel) {
                        if (!$highest) return $channel;
                        return $this->getResolution($channel) > $this->getResolution($highest) ? $channel : $highest;
                    });
                }
            }

            // Priority 2: Highest resolution overall
            return $group->reduce(function ($highest, $channel) {
                if (!$highest) return $channel;
                return $this->getResolution($channel) > $this->getResolution($highest) ? $channel : $highest;
            });
        } else {
            // Priority 1: Preferred playlist with earliest order
            if ($this->playlistId) {
                $preferredChannels = $group->where('playlist_id', $this->playlistId);
                if ($preferredChannels->isNotEmpty()) {
                    return $preferredChannels->sortBy(fn($channel) => $playlistPriority[$channel->playlist_id] ?? 999)->first();
                }
            }

            // Priority 2: Earliest playlist order overall
            return $group->sortBy(fn($channel) => $playlistPriority[$channel->playlist_id] ?? 999)->first();
        }
    }

    private function getResolution($channel)
    {
        $streamStats = $channel->stream_stats;
        foreach ($streamStats as $stream) {
            if (isset($stream['stream']['codec_type']) && $stream['stream']['codec_type'] === 'video') {
                return ($stream['stream']['width'] ?? 0) * ($stream['stream']['height'] ?? 0);
            }
        }
        return 0;
    }

    protected function sendCompletionNotification($processed)
    {
        $body = $processed > 0 ? "Merged {$processed} channels successfully." : 'No channels were merged.';

        Notification::make()
            ->title('Merge complete')
            ->body($body)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
