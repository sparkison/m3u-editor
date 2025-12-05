<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlistId,
        public int $totalChannels,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (!$playlist) {
            Log::error('ProcessVodChannelsComplete: Playlist not found', ['playlist_id' => $this->playlistId]);
            return;
        }

        // Check if anything else is still running
        $channelSyncRunning = $playlist->progress < 100;
        $seriesSyncRunning = $playlist->series_progress < 100;
        $anythingElseRunning = $channelSyncRunning || $seriesSyncRunning;

        // Update the VOD progress and set final status if nothing else is running
        $updateData = [
            'vod_progress' => 100,
        ];
        
        if (!$anythingElseRunning) {
            $updateData['processing'] = false;
            $updateData['status'] = Status::Completed;
        }
        
        $playlist->update($updateData);

        Log::info('Completed processing VOD channels for playlist', [
            'playlist_id' => $playlist->id,
            'total_channels' => $this->totalChannels,
            'set_final_status' => !$anythingElseRunning,
        ]);

        Notification::make()
            ->success()
            ->title('VOD Metadata Processed')
            ->body("Successfully processed metadata for {$this->totalChannels} VOD channels in playlist \"{$playlist->name}\".")
            ->broadcast($playlist->user)
            ->sendToDatabase($playlist->user);

        // Fire the sync completed event if this is the final step
        if (!$anythingElseRunning) {
            event(new SyncCompleted($playlist));
        }
    }
}
