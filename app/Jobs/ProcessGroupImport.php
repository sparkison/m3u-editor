<?php

namespace App\Jobs;

use App\Models\Group;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class ProcessGroupImport implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Collection $groups,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find/create the groups
        foreach ($this->groups as $group) {
            $model = Group::firstOrCreate([
                'playlist_id' => $group['playlist_id'],
                'user_id' => $group['user_id'],
                'name' => $group['name'],
            ]);
            $model->update([
                ...$group,
            ]);
        }
    }
}
