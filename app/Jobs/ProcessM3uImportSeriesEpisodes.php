<?php

namespace App\Jobs;

use App\Models\Series;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesEpisodes implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Series $playlistSeries,
        public bool $notify = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Initialize the Xtream API
        if (!$this->playlistSeries) {
            return;
        }

        // Get the playlist
        $playlist = $this->playlistSeries->playlist;

        // Process the series
        $results = $this->playlistSeries->fetchMetadata();
        if ($this->notify && $results !== false) {
            // Check if the playlist has .strm file sync enabled
            $sync_settings = $this->playlistSeries->sync_settings;
            $syncStrmFiles = $sync_settings['enabled'] ?? false;
            $body = "Series sync completed successfully for \"{$this->playlistSeries->name}\". Imported {$results} episodes.";
            if ($syncStrmFiles) {
                $body .= " .strm file sync is enabled, syncing now.";
            } else {
                $body .= " .strm file sync is not enabled.";
            }
            Notification::make()
                ->success()
                ->title('Series Sync Completed')
                ->body($body)
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }
}
