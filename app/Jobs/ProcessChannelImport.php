<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChannelImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public string $batchNo,
        public ?Group $group,
        public array $channels
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Link the channel groups to the channels
        foreach ($this->channels as $channel) {
            // Make sure name is set
            if (!isset($channel['name'])) {
                continue;
            }

            // Find/create the channel
            $model = Channel::firstOrCreate([
                'name' => $channel['name'],
                'group' => $channel['group'],
                'playlist_id' => $channel['playlist_id'],
                'user_id' => $channel['user_id'],
            ]);

            // Don't overwrite channel the logo if currently set
            if ($model->logo) {
                unset($channel['logo']);
            }

            // Update the channel with the group ID
            $model->update([
                ...$channel,
                'group_id' => $this->group?->id ?? null,
            ]);
        }
    }
}
