<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesComplete implements ShouldQueue
{
    use Queueable;

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
        // Cleanup the series that no longer exist in the playlist
        $this->playlist->series()
            ->where('import_batch_no', '!=', $this->batchNo)
            ->delete();

        // Update the playlist status to synced
        $this->playlist->update([
            'processing' => false,
            'status' => Status::Completed,
            'errors' => null,
            'series_progress' => 100,
        ]);
        Notification::make()
            ->success()
            ->title('Series Sync Completed')
            ->body("Series sync completed successfully for playlist \"{$this->playlist->name}\".")
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);
    }
}
