<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Job;
use App\Models\JobProgress;
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
        // Update the playlist status to synced
        $this->playlist->refresh();
        $this->playlist->update([
            'processing' => [
                ...$this->playlist->processing ?? [],
                'series_processing' => false,
            ],
            'status' => Status::Completed,
            'errors' => null,
            'series_progress' => 100,
        ]);

        // Mark job progress as completed for series jobs
        JobProgress::forTrackable($this->playlist)
            ->where('job_type', ProcessM3uImportSeries::class)
            ->active()
            ->each(fn (JobProgress $job) => $job->complete('Series sync completed successfully.'));

        // Sync stream files for series, if enabled
        if ($this->playlist->auto_sync_series_stream_files) {
            dispatch(new SyncSeriesStrmFiles(
                playlist_id: $this->playlist->id,
                user_id: $this->playlist->user_id,
            ));
        }

        $message = "Series sync completed successfully for playlist \"{$this->playlist->name}\".";
        Notification::make()
            ->success()
            ->title('Series Sync Completed')
            ->body($message)
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);

        // Fire the playlist synced event
        event(new SyncCompleted($this->playlist));
    }
}
