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

            // Process all series in smaller chunks to reduce memory pressure
            // Each series makes an API call and potentially creates hundreds of episodes
            $processedCount = 0;
            Series::query()
                ->where([
                    ['enabled', true],
                    ['user_id', $this->user_id],
                ])
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->with(['playlist'])
                ->chunkById(10, function ($seriesChunk) use ($global_sync_settings, &$processedCount) {
                    foreach ($seriesChunk as $series) {
                        // In bulk mode, don't dispatch sync per series - we do it once at the end
                        $this->fetchMetadataForSeries($series, $global_sync_settings, dispatchSync: false);
                        $processedCount++;

                        // Unload relations to free memory after processing each series
                        $series->unsetRelations();
                    }

                    // Free memory between chunks
                    gc_collect_cycles();
                });

            // Dispatch a SINGLE SyncSeriesStrmFiles job at the end instead of per-series
            // This dramatically reduces queue pressure and allows bulk optimizations
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
     * Process a single series and fetch its metadata.
     *
     * @param  Series  $series  The series to process
     * @param  array  $settings  Global sync settings
     * @param  bool  $dispatchSync  Whether to dispatch sync job for this series (false for bulk mode)
     */
    private function fetchMetadataForSeries($series, $settings, bool $dispatchSync = true)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // Use provider throttling to limit concurrent requests and apply delay
        // In bulk mode (dispatchSync=false), we don't dispatch individual sync jobs
        // The bulk sync job is dispatched once at the end of processing
        $results = $this->withProviderThrottling(function () use ($series, $dispatchSync) {
            return $series->fetchMetadata(
                refresh: $this->overwrite_existing,
                sync: $dispatchSync && $this->sync_stream_files
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
