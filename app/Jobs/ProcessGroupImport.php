<?php

namespace App\Jobs;

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
        public int $userId,
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

        // Get the groups
        $playlistId = $this->playlistId;
        $userId = $this->userId;
        $batchNo = $this->batchNo;

        // Get the group names from this batch of channels
        $groupNames = array_map(fn($group) => $group['name'], $this->groups);
        $groupWhere = [
            ['playlist_id', $playlistId],
            ['user_id', $userId],
        ];

        // Update the group batch number
        Group::where($groupWhere)
            ->whereIn('name', $groupNames)
            ->update(['import_batch_no' => $batchNo]);

        // Get the groups
        $groups = Group::where($groupWhere)
            ->whereIn('name', $groupNames)
            ->select('id', 'name')->get();

        // Create the groups if they don't exist
        foreach ($this->groups as $group) {
            // Check if the group already exists
            if ($groups->contains('name', $group['name'])) {
                continue;
            }

            // Doesn't exist, create it!
            Group::create($group);
        }
    }
}
