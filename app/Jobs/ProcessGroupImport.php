<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;


class ProcessGroupImport implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public string $batchNo,
        public array $groups
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

        // Link the channel groups to the channels
        foreach ($this->groups as $group) {
            $model = Group::firstOrCreate([
                'name' => $group['name'],
                'playlist_id' => $group['playlist_id'],
                'user_id' => $group['user_id'],
            ]);

            // Update the channel with the group ID
            $model->update([
                ...$group,
            ]);
        }
    }
}
