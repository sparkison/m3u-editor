<?php

namespace App\Jobs;

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
        public int $epgId,
        public string $batchNo,
        public array $programs,
        public array $channels,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
