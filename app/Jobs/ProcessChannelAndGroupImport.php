<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChannelAndGroupImport implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public string $batchNo,
        public array $channels
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
        foreach ($this->channels as $channel) {
            // Find/create the channel
            $model = Channel::firstOrCreate([
                'playlist_id' => $channel['playlist_id'],
                'user_id' => $channel['user_id'],
                'name' => $channel['name'],
                'group' => $channel['group'],
            ]);

            // Don't overwrite the logo if currently set
            if ($model->logo) {
                unset($channel['logo']);
            }

            // Get or create the group
            $group = Group::firstOrCreate([
                'playlist_id' => $channel['playlist_id'],
                'user_id' => $channel['user_id'],
                'name' => $channel['group'],
            ]);

            // Update the group with the batch number (if not already set)
            if ($group->import_batch_no !== $this->batchNo) {
                $group->update([
                    'import_batch_no' => $this->batchNo
                ]);
            }

            // Update the channel with the group ID
            $model->update([
                ...$channel,
                'group_id' => $group->id
            ]);
        }
    }
}
