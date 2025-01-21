<?php

namespace App\Jobs;

use App\Enums\EpgStatus;
use App\Models\Epg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgImport implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 600;

    /**
     * Create a new job instance.
     * 
     * @param Epg $epg
     */
    public function __construct(
        public Epg $epg
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Don't update if currently processing
        if ($this->epg->status === EpgStatus::Processing) {
            return;
        }

        // Update the playlist status to processing
        $this->epg->update([
            'status' => EpgStatus::Processing,
            'errors' => null,
        ]);

        
        // @TODO: process the EPG file...


        $this->epg->update([
            'status' => EpgStatus::Completed,
            'errors' => null,
        ]);
    }
}
