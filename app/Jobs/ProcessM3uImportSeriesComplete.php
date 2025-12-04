<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Job;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string   $batchNo,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = $this->playlist;
        
        // Check if VOD sync is still running
        $vodStillRunning = $playlist->vod_progress < 100 
            && ($playlist->auto_fetch_vod_metadata || $playlist->auto_sync_vod_stream_files);

        // Update the playlist status
        $playlist->update([
            'processing' => $vodStillRunning,
            'status' => $vodStillRunning ? Status::Processing : Status::Completed,
            'errors' => null,
            'progress' => 100,
            'series_progress' => 100,
        ]);
        
        $message = "Series sync completed successfully for playlist \"{$playlist->name}\".";
        Notification::make()
            ->success()
            ->title('Series Sync Completed')
            ->body($message)
            ->broadcast($playlist->user)
            ->sendToDatabase($playlist->user);

        // Only fire sync completed event if VOD is not still running
        if (!$vodStillRunning) {
            event(new SyncCompleted($playlist));
        }
    }
}
