<?php

namespace App\Jobs;

use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class UnmergeChannels implements ShouldQueue
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
        $channelIds = $this->channels->pluck('id');

        // Delete all failover records where the selected channels are either the master or the failover
        ChannelFailover::whereIn('channel_id', $channelIds)
            ->orWhereIn('channel_failover_id', $channelIds)
            ->delete();
    }
}
