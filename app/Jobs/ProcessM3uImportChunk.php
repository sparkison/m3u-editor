<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportChunk implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $jobs,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $job) {
            $bulk = [];
            $groupId = $job->variables['groupId'];
            foreach ($job->payload as $channel) {
                // Make sure name is set
                if (!isset($channel['name'])) {
                    continue;
                }

                // Add the channel for insert/update
                $bulk[] = [
                    ...$channel,
                    'group_id' => $groupId ?? null,
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
                'country', // should we update this? Not sure it's set anywhere...
                'import_batch_no',
            ]);
        }
    }
}
