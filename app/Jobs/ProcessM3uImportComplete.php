<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportComplete implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $playlistId,
        public array $groups,
        public string $batchNo,
        public Carbon $start,
        public bool $maxHit = false
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        $user = User::find($this->userId);
        $playlist = $user->playlists()->find($this->playlistId);

        // Send notification
        if ($this->maxHit) {
            $limit = config('dev.max_channels');
            Notification::make()
                ->warning()
                ->title('Playlist Synced with Limit Reached')
                ->body("\"{$playlist->name}\" has been synced successfully, but the maximum import limit of {$limit} channels was reached.")
                ->broadcast($playlist->user);
            Notification::make()
                ->warning()
                ->title('Playlist Synced with Limit Reached')
                ->body("\"{$playlist->name}\" has been synced successfully, but the maximum import limit of {$limit} channels was reached. Some channels may not have been imported. Import completed in {$completedInRounded} seconds.")
                ->sendToDatabase($playlist->user);
        } else {
            Notification::make()
                ->success()
                ->title('Playlist Synced')
                ->body("\"{$playlist->name}\" has been synced successfully.")
                ->broadcast($playlist->user);
            Notification::make()
                ->success()
                ->title('Playlist Synced')
                ->body("\"{$playlist->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
                ->sendToDatabase($playlist->user);
        }

        // Clear out invalid groups (if any)
        Group::where([
            ['custom', false],
            ['playlist_id', $playlist->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out invalid channels (if any)
        Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out the jobs
        Job::where(['batch_no', $this->batchNo])->delete();

        // Update the import preferences
        if ($playlist->import_prefs['preprocess'] ?? false) {
            $importPrefs = [
                ...$playlist->import_prefs ?? [],

                // Make sure there's no selected groups that are no longer in the available groups
                'selected_groups' => array_intersect($playlist->import_prefs['selected_groups'] ?? [], $this->groups),
            ];
        } else {
            // no changes to import prefs
            $importPrefs = $playlist->import_prefs;
        }

        // Check if creating EPG
        $createEpg = $playlist->xtream
            ? ($playlist->xtream_config['import_epg'] ?? false)
            : null;
        if ($createEpg) {
            // Configure the EPG url
            $baseUrl = str($playlist->xtream_config['url'])->replace(' ', '%20')->toString();
            $username = urlencode($playlist->xtream_config['username']);
            $password = $playlist->xtream_config['password'];
            $epgUrl = "$baseUrl/xmltv.php?username=$username&password=$password";

            // Make sure EPG doesn't already exist
            $epg = $user->epgs()->where('url', $epgUrl)->first();
            if (!$epg) {
                $headers = @get_headers($epgUrl);
                if (strpos($headers[0], '200') !== false) {
                    // EPG found, create it
                    $epg = $user->epgs()->create([
                        'name' => $playlist->name . ' EPG',
                        'url' => $epgUrl,
                        'user_id' => $user->id,
                    ]);
                    $msg = "\"{$playlist->name}\" EPG was created and is syncing now.";
                    Notification::make()
                        ->success()
                        ->title('EPG found for Playlist')
                        ->body($msg)
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->success()
                        ->title('EPG found for Playlist')
                        ->body($msg)
                        ->sendToDatabase($playlist->user);
                } else {
                    $msg = "\"{$playlist->name}\" EPG not found. Playlist was configured to auto-download EPG but no EPG was found using at the following url: \"$epgUrl\"";
                    Notification::make()
                        ->warning()
                        ->title('No EPG found for Playlist')
                        ->body($msg)
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->warning()
                        ->title('No EPG found for Playlist')
                        ->body($msg)
                        ->sendToDatabase($playlist->user);
                }
            }
        }

        // Update the playlist
        $playlist->update([
            'status' => Status::Completed,
            'channels' => 0, // not using...
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'progress' => 100,
            'processing' => false,
            'import_prefs' => $importPrefs,
            'groups' => $this->groups,
        ]);

        // Fire the playlist synced event
        event(new SyncCompleted($playlist));
    }
}
