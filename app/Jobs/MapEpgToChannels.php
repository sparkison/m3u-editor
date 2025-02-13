<?php

namespace App\Jobs;

use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MapEpgToChannels implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channels
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Just update the epg_channel_id field
        Channel::upsert($this->channels, uniqueBy: ['name', 'group', 'playlist_id', 'user_id'], update: [
            // Don't update the following fields...
            // 'title',
            // 'name',
            // 'group',
            // 'playlist_id',
            // 'user_id',
            // 'logo',
            // ...only update the following fields
            // 'url',
            // 'stream_id',
            // 'lang', // should we update this? Not sure it's set anywhere...
            // 'country', // should we update this? Not sure it's set anywhere...
            // 'import_batch_no',
            'epg_channel_id', // this is the only field we want to update...
        ]);
    }
}
