<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
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
        public int $count,
        public Collection $groups,
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

        try {
            // Get the groups
            $groups = $this->groups;

            // Link the channel groups to the channels
            $this->channels->map(function ($channel) use ($groups) {
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
                    'group_id' => $groups->firstWhere('name', $channel['group'])['id']
                ]);
                return $channel;
            });

            // @TODO - remove orphaned channels
            // Tricky because channels imported across multiple jpbs...
            
        } catch (\Exception $e) {
            // Log the exception
            logger()->error($e->getMessage());
        }
        return;
    }
}
