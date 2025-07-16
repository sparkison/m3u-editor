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

    public $user;
    public $playlistId;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channels, $user, $playlistId = null)
    {
        $this->user = $user;
        $this->playlistId = $playlistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Group channels by their effective stream ID
        $groupedChannels = $this->channels->groupBy(function ($channel) {
            return $channel->stream_id_custom ?: $channel->stream_id;
        });

        foreach ($groupedChannels as $group) {
            if ($group->count() > 1) {
                // Designate the first channel as the master
                if ($this->playlistId) {
                    $master = $group->first(function ($channel) {
                        return $channel->playlist_id == $this->playlistId;
                    });
                    if (!$master) {
                        $master = $group->shift();
                    } else {
                        $group = $group->filter(function ($channel) use ($master) {
                            return $channel->id !== $master->id;
                        });
                    }
                } else {
                    $master = $group->shift();
                }

                // The rest are failovers
                foreach ($group as $failover) {
                    ChannelFailover::updateOrCreate(
                        [
                            'channel_id' => $master->id,
                            'channel_failover_id' => $failover->id,
                        ],
                        [
                            'user_id' => $master->user_id,
                        ]
                    );
                    $processed++;
                }
            }
        }
        $this->sendCompletionNotification();
    }

    protected function sendCompletionNotification()
    {
        Notification::make()
            ->title('Merge complete')
            ->body('All channels have been merged successfully.')
            ->success()
            ->sendToDatabase($this->user);
    }
}
