<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesEpisodes implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Series $playlistSeries = null,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public bool $overwrite_existing = false,
        public ?int $user_id = null,
        public ?bool $sync_stream_files = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get the series
        $series = $this->playlistSeries;

        // Get global sync settings
        $global_sync_settings = [
            'enabled' => $settings->stream_file_sync_enabled ?? false,
        ];

        if ($series) {
            $this->fetchMetadataForSeries($series, $global_sync_settings);
        } else {
            // Disable notifications for bulk processing
            $this->notify = false;

            // Process all series in chunks
            // In bulk mode, don't dispatch individual sync jobs - we do ONE at the end
            Series::query()
                ->where([
                    ['enabled', true],
                    ['user_id', $this->user_id],
                ])
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->with(['playlist'])
                ->chunkById(50, function ($seriesChunk) use ($global_sync_settings) {
                    foreach ($seriesChunk as $series) {
                        // Pass dispatchSync: false to prevent per-series job dispatch
                        $this->fetchMetadataForSeries($series, $global_sync_settings, dispatchSync: false);
                    }
                });

            // Dispatch ONE bulk sync job at the end instead of per-series
            if ($global_sync_settings['enabled'] && $this->sync_stream_files) {
                dispatch(new SyncSeriesStrmFiles(
                    series: null,
                    notify: false,
                    all_playlists: $this->all_playlists,
                    playlist_id: $this->playlist_id,
                    user_id: $this->user_id,
                ));
            }

            // Notify the user we're done!
            if ($this->user_id) {
                $user = User::find($this->user_id);
                if ($user) {
                    Notification::make()
                        ->success()
                        ->title('Series Sync Completed')
                        ->body('Series sync completed successfully for all series.')
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            }
        }
    }

    /**
     * Fetch metadata for a single series.
     *
     * @param  bool  $dispatchSync  Whether to dispatch sync job (false for bulk mode)
     */
    private function fetchMetadataForSeries($series, $settings, bool $dispatchSync = true)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // In bulk mode (dispatchSync=false), don't trigger per-series sync
        $shouldSync = $dispatchSync && $this->sync_stream_files;

        // Use provider throttling to limit concurrent requests and apply delay
        $results = $this->withProviderThrottling(function () use ($series, $shouldSync) {
            return $series->fetchMetadata(
                refresh: $this->overwrite_existing,
                sync: $shouldSync
            );
        });

        if ($this->notify && $results !== false) {
            // Check if the playlist has .strm file sync enabled
            $sync_settings = $series->sync_settings;
            $syncStrmFiles = $settings['enabled'] ?? $sync_settings['enabled'] ?? false;
            $body = "Series sync completed successfully for \"{$series->name}\". Imported {$results} episodes.";
            if ($syncStrmFiles) {
                $body .= ' .strm file sync is enabled, syncing now.';
            } else {
                $body .= ' .strm file sync is not enabled.';
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
