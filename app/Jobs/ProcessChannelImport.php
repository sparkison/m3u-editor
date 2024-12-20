<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class ProcessChannelImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
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
        try {
            // Get the playlist id
            $playlistId = $this->playlist->id;

            // Keep track of new channels and groups
            $new_channels = [];
            $new_groups = [];

            // Find/create the groups
            $groups = $this->groups->map(function ($group) use (&$new_groups) {
                $model = Group::firstOrCreate([
                    'playlist_id' => $group['playlist_id'],
                    'user_id' => $group['user_id'],
                    'name' => $group['name'],
                ]);

                // Keep track of groups
                $new_groups[] = $model->id;

                // Return the group, with the ID
                return [
                    ...$group,
                    'id' => $model->id,
                ];
            });

            // Link the channel groups to the channels
            $this->channels->map(function ($channel) use ($groups, &$new_channels) {
                // Find/create the channel
                $model = Channel::firstOrCreate([
                    'playlist_id' => $channel['playlist_id'],
                    'user_id' => $channel['user_id'],
                    'name' => $channel['name'],
                    'group' => $channel['group'],
                ]);

                // Keep track of channels
                $new_channels[] = $model->id;

                // Update the channel
                $model->update([
                    ...$channel,
                    'group_id' => $groups->firstWhere('name', $channel['group'])['id']
                ]);
                return $channel;
            });

            // Remove orphaned channels and groups
            Channel::where('playlist_id', $playlistId)
                ->whereNotIn('id', $new_channels)
                ->delete();

            Group::where('playlist_id', $playlistId)
                ->whereNotIn('id', $new_groups)
                ->delete();

            // Update the playlist
            $this->playlist->update([
                'status' => PlaylistStatus::Completed,
                'channels' => $this->count,
                'synced' => now(),
                'errors' => null,
            ]);

            // Send notification
            Notification::make()
                ->success()
                ->title('Playlist Synced')
                ->body("'{$this->playlist->name}' has been successfully synced.")
                ->broadcast($this->playlist->user);
            return;
        } catch (\Exception $e) {
            // Log the exception
            logger()->error($e->getMessage());

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error importing channels from '{$this->playlist->name}'")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error importing channels from '{$this->playlist->name}'")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => PlaylistStatus::Failed,
                'channels' => 0,
                'synced' => now(),
                'errors' => $e->getMessage(),
            ]);
            return;
        }
    }
}
