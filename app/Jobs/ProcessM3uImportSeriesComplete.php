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
        public ?bool    $fetchedMeta = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Update the playlist status to synced
        $this->playlist->update([
            'processing' => false,
            'status' => Status::Completed,
            'errors' => null,
            'series_progress' => 100,
        ]);
        $message = "Series sync completed successfully for playlist \"{$this->playlist->name}\".";
        if (!$this->fetchedMeta) {
            $message = " Enable series to fetch metadata and new episodes on next playlist sync, or you can manually fetch metadata for any imported series.";
        }
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
