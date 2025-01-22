<?php

namespace App\Jobs;

use App\Models\EpgProgramme;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgProgrammeImport implements ShouldQueue
{
    use Batchable, Queueable;

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
        if ($this->batch()->cancelled()) {
            // Determine if the batch has been cancelled...
            return;
        }
        
        // Create the programmes
        foreach ($this->programmes as $programme) {
            EpgProgramme::create([
                ...$programme
            ]);
        }
    }
}
