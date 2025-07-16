<?php

namespace App\Jobs;

use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;

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
        $total = $this->channels->count();
        $processed = 0;

        $channelIds = $this->channels->pluck('id');

        // Delete all failover records where the selected channels are either the master or the failover
        ChannelFailover::whereIn('channel_id', $channelIds)
            ->orWhereIn('channel_failover_id', $channelIds)
            ->delete();

        foreach ($this->channels as $channel) {
            $processed++;
            $this->updateProgress($processed, $total);
        }

        $this->sendCompletionNotification();
    }

    protected function updateProgress($processed, $total)
    {
        $progress = round(($processed / $total) * 100);
        Notification::make()
            ->title('Unmerging channels...')
            ->body("Processed {$processed} of {$total} channels.")
            ->success()
            ->progress($progress)
            ->sendToDatabase($this->user);
    }

    protected function sendCompletionNotification()
    {
        Notification::make()
            ->title('Unmerge complete')
            ->body('All channels have been unmerged successfully.')
            ->success()
            ->sendToDatabase($this->user);
    }
}
