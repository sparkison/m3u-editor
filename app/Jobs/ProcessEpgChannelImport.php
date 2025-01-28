<?php

namespace App\Jobs;

use App\Models\EpgChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgChannelImport implements ShouldQueue
{
    use Queueable;

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
        // Upsert the channels
        EpgChannel::upsert($this->channels, uniqueBy: ['name', 'channel_id', 'epg_id', 'user_id'], update: [
            // Don't update the following fields...
            // 'name',
            // 'epg_id',
            // 'user_id',
            // ...only update the following fields
            'display_name',
            'lang',
            'icon',
            'channel_id',
            'import_batch_no',
        ]);
    }
}
