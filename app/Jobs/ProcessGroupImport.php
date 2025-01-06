<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessGroupImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public int $count,
        public Collection $groups,
        public Collection $channels,
        public string $batchNo
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
            $batchNo = $this->batchNo;

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

            // Remove orphaned groups
            Group::where('playlist_id', $playlistId)
                ->whereNotIn('id', $new_groups)
                ->delete();

            // Batch the channel import
            $count = $this->count;
            /** @var Playlist $playlist */
            $playlist = $this->playlist;
            $jobs = collect([]);
            foreach ($this->channels->chunk(100) as $chunk) {
                $jobs->push(new ProcessChannelImport($count, $groups, $chunk));
            }
            Bus::batch($jobs)
                ->then(function (Batch $batch) use ($playlist, $count, $batchNo) {
                    // All jobs completed successfully...

                    // Send notification
                    Notification::make()
                        ->success()
                        ->title('Playlist Synced')
                        ->body("\"{$playlist->name}\" has been synced successfully.")
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->success()
                        ->title('Playlist Synced')
                        ->body("\"{$playlist->name}\" has been synced successfully.")
                        ->sendToDatabase($playlist->user);

                    // Clear out invalid channels (if any)
                    Channel::where([
                        ['playlist_id', $playlist->id],
                        ['import_batch_no', '!=', $batchNo],
                    ])->delete();

                    // Update the playlist
                    $playlist->update([
                        'status' => PlaylistStatus::Completed,
                        'channels' => $count,
                        'synced' => now(),
                        'errors' => null,
                    ]);
                })->catch(function (Batch $batch, Throwable $e) {
                    // First batch job failure detected...
                })->finally(function (Batch $batch) {
                    // The batch has finished executing...
                })->name('Playlist channel import')->dispatch();
            return;
        } catch (\Exception $e) {
            // Log the exception
            logger()->error($e->getMessage());

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error importing groups from \"{$this->playlist->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error importing groups from \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => PlaylistStatus::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
            ]);
            return;
        }
    }
}
