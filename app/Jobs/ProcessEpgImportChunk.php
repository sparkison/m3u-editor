<?php

namespace App\Jobs;

use App\Models\EpgChannel;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgImportChunk implements ShouldQueue
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
            // $epgId = $job->variables['epgId'];
            foreach ($job->payload as $channel) {
                $bulk[] = [
                    ...$channel,
                ];
            }

            // Upsert the channels
            EpgChannel::upsert($bulk, uniqueBy: ['name', 'channel_id', 'epg_id', 'user_id'], update: [
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
}
