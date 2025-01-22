<?php

namespace App\Jobs;

use App\Models\EpgChannel;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgChannelImport implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channels,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()->cancelled()) {
            // Determine if the batch has been cancelled...
            return;
        }

        // Create the epg channels
        foreach ($this->channels as $channel) {
            // Find/create the epg channel
            $model = EpgChannel::firstOrCreate([
                'name' => $channel['name'],
                'channel_id' => $channel['channel_id'],
                'epg_id' => $channel['epg_id'],
                'user_id' => $channel['user_id'],
            ]);

            $model->update([
                ...$channel,
            ]);
        }
    }
}
