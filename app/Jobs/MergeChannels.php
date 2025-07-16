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

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channels)
    {
        //
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
                $master = $group->shift();

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
                }
            }
        }
    }
}
