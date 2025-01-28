<?php

namespace App\Jobs;

use App\Models\EpgProgramme;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgProgrammeImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $programmes,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Upsert the channels
        EpgProgramme::upsert($this->programmes, uniqueBy: ['name', 'channel_id', 'epg_id', 'user_id'], update: [
            // Don't update the following fields...
            // 'name',
            // 'channel_id',
            // 'epg_id',
            // 'user_id',
            // ...only update the following fields
            'data',
            'import_batch_no',
        ]);
    }
}
