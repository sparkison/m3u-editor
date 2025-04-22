<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\PostProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

class RunPostProcess implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     * 
     * @param PostProcess $postProcess
     * @param Model $model
     */
    public function __construct(
        public PostProcess $postProcess,
        public Model $model,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        dump('Running post process job');
    }
}
