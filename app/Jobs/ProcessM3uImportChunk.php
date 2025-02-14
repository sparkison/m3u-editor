<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Job;
use App\Models\Playlist;
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
        public int $batchCount,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Determine what percentage of the import this batch accounts for
        $totalJobsCount = $this->batchCount;
        $chunkSize = 20;

        // Process the jobs
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $index => $job) {
            $bulk = [];
            if ($index % $chunkSize === 0) {
                $playlist = Playlist::find($job->variables['playlistId']);
                $playlist->update([
                    'progress' => $playlist->progress + ($chunkSize / $totalJobsCount) * 100,
                ]);
            }

            // Add the channel for insert/update
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
