<?php

namespace App\Jobs;

use App\Facades\ProxyFacade;
use App\Models\Series;
use App\Models\User;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSeriesStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Series $series = null,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public ?int $user_id = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get all the series episodes
        $series = $this->series;
        if ($series) {
            $this->fetchMetadataForSeries($series, $settings);
        } else {
            // Disable notifications for bulk processing
            $this->notify = false;

            // Process all series in chunks
            Series::query()
                ->where('enabled', true)
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->with(['enabled_episodes', 'playlist', 'user', 'category'])
                ->chunkById(100, function ($seriesChunk) use ($settings) {
                    foreach ($seriesChunk as $series) {
                        $this->fetchMetadataForSeries($series, $settings);
                    }
                });

            // Notify the user we're done!
            if ($this->user_id) {
                $user = User::find($this->user_id);
                if ($user) {
                    Notification::make()
                        ->success()
                        ->title("Sync .strm files for series completed")
                        ->body("Sync completed for all series.")
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            }
        }
    }

    private function fetchMetadataForSeries(Series $series, $settings)
    {
        $series->load('enabled_episodes', 'playlist', 'user', 'category');

        $playlist = $series->playlist;
        try {
            // Get playlist sync settings
            $sync_settings = $series->sync_settings;

            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->stream_file_sync_enabled ?? false,
                'include_category' => $settings->stream_file_sync_include_category ?? true,
                'include_series' => $settings->stream_file_sync_include_series ?? true,
                'include_season' => $settings->stream_file_sync_include_season ?? true,
                'sync_location' => $series->sync_location ?? $settings->stream_file_sync_location ?? null,
            ];

            // Merge global settings with series specific settings
            $sync_settings = array_merge($global_sync_settings, $sync_settings ?? []);

            // Check if sync is enabled
            if (!$sync_settings['enabled'] ?? false) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body("Sync is not enabled for this series.")
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                }
                return;
            }

            // Get the series episodes
            $episodes = $series->enabled_episodes;

            // Check if there are any episodes
            if ($episodes->isEmpty()) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body("No episodes found for this series. Try processing it first.")
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                }
                return;
            }

            // Get the path info
            $path = rtrim($sync_settings['sync_location'], '/');
            if (!is_dir($path)) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body("Sync location \"{$path}\" does not exist.")
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                } else {
                    Log::error("Error sync .strm files for series \"{$series->name}\": Sync location \"{$path}\" does not exist.");
                }
                return;
            }

            // See if the category is enabled, if not, skip, else create the folder
            if ($sync_settings['include_category'] ?? true) {
                // Create the category folder
                // Remove any special characters from the category name
                $category = $series->category;
                $catName = $category->name ?? $category->name_internal ?? 'Uncategorized';
                $cleanName = PlaylistService::makeFilesystemSafe($catName);
                $path .= '/' . $cleanName;
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            // See if the series is enabled, if not, skip, else create the folder
            if ($sync_settings['include_series'] ?? true) {
                // Create the series folder
                // Remove any special characters from the series name
                $cleanName = PlaylistService::makeFilesystemSafe($series->name);
                $path .= '/' . $cleanName;
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            // Loop through each episode
            foreach ($episodes as $ep) {
                // Setup episode prefix
                $season = $ep->season;
                $num = str_pad($ep->episode_num, 2, '0', STR_PAD_LEFT);
                $prefx = "S" . str_pad($season, 2, '0', STR_PAD_LEFT) . "E{$num}";

                // Create the .strm file
                $fileName = PlaylistService::makeFilesystemSafe("{$prefx} - {$ep->title}");
                $fileName = "{$fileName}.strm";

                // Create the season folder
                if ($sync_settings['include_season'] ?? true) {
                    $seasonPath = $path . '/Season ' . str_pad($season, 2, '0', STR_PAD_LEFT);
                    if (!is_dir($seasonPath)) {
                        mkdir($seasonPath, 0777, true);
                    }
                    $filePath = $seasonPath . '/' . $fileName;
                } else {
                    $filePath = $path . '/' . $fileName;
                }

                // Get the url
                $url = $ep->url;
                if ($playlist && $playlist->enable_proxy) {
                    $format = $episode->container_extension ?? $playlist->proxy_options['output'] ?? 'mp4';
                    $url = ProxyFacade::getProxyUrlForEpisode(
                        id: $ep->id,
                        format: $format
                    );
                }

                // Check if the file already exists
                if (file_exists($filePath)) {
                    // If the file exists, check if the URL is the same
                    $currentUrl = file_get_contents($filePath);
                    if ($currentUrl === $url) {
                        // Skip if the URL is the same
                        continue;
                    }
                }
                file_put_contents($filePath, $url);
            }

            // Notify the user
            if ($this->notify) {
                Notification::make()
                    ->success()
                    ->title("Sync .strm files for series \"{$series->name}\"")
                    ->body("Sync completed for series \"{$series->name}\".")
                    ->broadcast($series->user)
                    ->sendToDatabase($series->user);
            }
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title("Error sync .strm files for series \"{$series->name}\"")
                ->body("Error: {$e->getMessage()}")
                ->broadcast($series->user)
                ->sendToDatabase($series->user);
        }
    }
}
