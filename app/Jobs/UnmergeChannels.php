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

    public $user;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channels, $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $channelIds = $this->channels->pluck('id');

        // Delete all failover records where the selected channels are the master
        ChannelFailover::whereIn('channel_id', $channelIds)->delete();

        $this->sendCompletionNotification();
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
