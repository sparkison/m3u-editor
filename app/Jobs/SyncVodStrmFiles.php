<?php

namespace App\Jobs;

use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncVodStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public bool $notify = true,
        public ?Channel $channel = null,
        public ?Collection $channels = null,
        public ?Playlist $playlist = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        try {
            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->vod_stream_file_sync_enabled ?? false,
                'include_season' => $settings->vod_stream_file_sync_include_season ?? true,
                'sync_location' => $settings->vod_stream_file_sync_location ?? null,
            ];

            // Setup our channels to sync
            $channels = $this->channels ?? collect();
            if ($this->channel) {
                $channels->push($this->channel);
            } else if ($this->playlist) {
                $channels = $this->playlist->channels()
                    ->where([
                        ['is_vod', true],
                        ['enabled', true],
                        ['source_id', '!=', null],
                    ])
                    ->get();
            }

            // Loop through each channel and sync
            foreach ($channels as $channel) {
                $sync_settings = array_merge($global_sync_settings, $channel->sync_settings ?? []);
                if (!$sync_settings['enabled'] ?? false) {
                    continue;
                }

                // Get the path info
                $path = rtrim($sync_settings['sync_location'], '/');
                if (!is_dir($path)) {
                    if ($this->notify) {
                        Notification::make()
                            ->danger()
                            ->title("Error sync .strm files for VOD channel \"{$channel->title}\"")
                            ->body("Sync location \"{$path}\" does not exist.")
                            ->broadcast($channel->user)
                            ->sendToDatabase($channel->user);
                    } else {
                        Log::error("Error sync .strm files for VOD channel \"{$channel->title}\": Sync location \"{$path}\" does not exist.");
                    }
                    return;
                }

                // Setup episode prefix
                $group = PlaylistService::makeFilesystemSafe($channel->group);

                // Create the .strm file
                $title = PlaylistService::makeFilesystemSafe($channel->title);
                $fileName = "{$title}.strm";

                // Create the season folder
                if ($sync_settings['include_season'] ?? true) {
                    $groupPath = $path . '/' . $group;
                    if (!is_dir($groupPath)) {
                        mkdir($groupPath, 0777, true);
                    }
                    $filePath = $groupPath . '/' . $fileName;
                } else {
                    $filePath = $path . '/' . $fileName;
                }

                // Get the url
                $url = $channel->url_custom ?? $channel->url;
                if ($playlist = $channel->getEffectivePlaylist()) {
                    if ($playlist->enable_proxy) {
                        $format = $channel->container_extension ?? $playlist->proxy_options['output'] ?? 'mkv';
                        $url = ProxyFacade::getProxyUrlForChannel(
                            id: $channel->id,
                            format: $format
                        );
                    }
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
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            Log::error('Error syncing VOD .strm files: ' . $e->getMessage());
        }
    }
}
