<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChannelImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public string $batchNo,
        public ?Group $group,
        public array $channels
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Link the channel groups to the channels
        $bulk = [];
        foreach ($this->channels as $channel) {
            // Make sure name is set
            if (!isset($channel['name'])) {
                continue;
            }

            // Add the channel for insert/update
            $bulk[] = [
                ...$channel,
                'group_id' => $this->group?->id ?? null,
            ];
        }

        // Upsert the channels
        Channel::upsert($bulk, uniqueBy: ['name', 'group', 'playlist_id', 'user_id'], update: [
            // Don't update the following fields...
            // 'title',
            // 'name',
            // 'group',
            // 'playlist_id',
            // 'user_id',
            // 'logo',
            // ...only update the following fields
            'url',
            'stream_id',
            'lang', // should we update this? Not sure it's set anywhere...
            'country',// should we update this? Not sure it's set anywhere...
            'import_batch_no',
        ]);
    }
}
