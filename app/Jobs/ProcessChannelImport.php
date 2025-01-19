<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class ProcessChannelImport implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public string $batchNo,
        public Collection $channels
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
        /** @var Collection $groups */
        $groups = Group::where([
            ['playlist_id', $this->playlistId],
            ['import_batch_no', $this->batchNo]
        ])->get(['id', 'name']);

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
            $model->update([
                ...$channel,
                'group_id' => $groups->firstWhere('name', $channel['group'])->id
            ]);
        }
    }
}
