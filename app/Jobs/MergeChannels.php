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
        public $playlistId,
        public bool $checkResolution = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Keep track of how many channels were processed
        $processed = 0;

        // Get the channels from the specified playlists
        $primaryChannels = Channel::where('playlist_id', $this->playlistId)
            ->where(function ($query) {
                $query->whereNotNull('stream_id_custom')->orWhereNotNull('stream_id');
            })->cursor();

        // Get the failover channels from the specified playlists
        $failoverChannels = Channel::whereIn('playlist_id', $this->playlists)
            ->where('playlist_id', '!=', $this->playlistId) // Exclude primary playlist channels
            ->where(function ($query) {
                $query->whereNotNull('stream_id_custom')->orWhereNotNull('stream_id');
            });

        // Loop through primary channels and assign any matching failover channels
        foreach ($primaryChannels as $channel) {
            // Skip if channel has no stream ID to match against
            if (!$channel->stream_id_custom && !$channel->stream_id) {
                continue;
            }

            // Check if any of the failovers have the same stream ID
            $matchingFailovers = $failoverChannels->clone() // make a copy of the query
                ->where(function ($query) use ($channel) {
                    if ($channel->stream_id_custom) {
                        $query->where('stream_id_custom', $channel->stream_id_custom);
                    }
                    if ($channel->stream_id) {
                        if ($channel->stream_id_custom) {
                            $query->orWhere('stream_id', $channel->stream_id);
                        } else {
                            $query->where('stream_id', $channel->stream_id);
                        }
                    }
                })->get();

            if ($matchingFailovers->isEmpty()) {
                continue; // No matching failovers, skip to the next channel
            }

            // If checkResolution is true, we need to order by resolution instead of playlist order
            if ($this->checkResolution) {
                $matchingFailovers = $matchingFailovers
                    ->sortBy(fn($failover) => $this->getResolution($failover))
                    ->reverse(); // Highest resolution first
            } else {
                // We need to check the order of the passed in playlists, that should be the order of priority
                $matchingFailovers = $matchingFailovers
                    ->sortBy(fn($failover) => $this->playlists->search($failover->playlist_id)); // Sort by the order of playlists
            }

            // Create failover relationships
            foreach ($matchingFailovers as $failover) {
                ChannelFailover::updateOrCreate(
                    ['channel_id' => $channel->id, 'channel_failover_id' => $failover->id],
                    ['user_id' => $channel->user_id]
                );
                $processed++;
            }
        }
        $this->sendCompletionNotification($processed);
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
