<?php

namespace App\Jobs;

use App\Models\Playlist;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DuplicatePlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public ?string $name = null,
        public bool $withSync = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $copiedPath = null;
        DB::beginTransaction();
        $copiedPath = null;

        try {
            // Get the base playlist
            $playlist = $this->playlist;

            // Prevent duplicating child playlists altogether
            if ($playlist->parent_id) {
                throw new \InvalidArgumentException('Child playlists cannot be duplicated.');
            }

            // Current timestamp
            $now = now();

            // Create a new playlist
            $newPlaylist = $playlist->replicate(except: [
                'id',
                'name',
                'uuid',
                'short_urls_enabled',
                'short_urls',
            ]);
            $newPlaylist->name = $this->name ?? $playlist->name.' (Copy)';
            $newPlaylist->uuid = Str::orderedUuid()->toString();
            $newPlaylist->created_at = $now;
            $newPlaylist->updated_at = $now;
            if ($this->withSync) {
                $newPlaylist->parent_id = $playlist->id;
                $newPlaylist->auto_sync = false;
            }
            $newPlaylist->saveQuietly(); // Don't fire model events to prevent auto sync

            // Map of original channel IDs to their duplicated counterparts
            $channelIdMap = [];

            // Copy the groups
            foreach ($playlist->groups()->lazy() as $group) {
                $newGroup = $group->replicate(except: [
                    'id',
                    'playlist_id',
                ]);
                $newGroup->playlist_id = $newPlaylist->id;
                $newGroup->created_at = $now;
                $newGroup->updated_at = $now;
                $newGroup->save();

                // Copy the group channels
                foreach ($group->channels()->with('failovers')->lazy() as $channel) {
                    $newChannel = $channel->replicate(except: [
                        'id',
                        'group_id',
                        'playlist_id',
                    ]);
                    $newChannel->group_id = $newGroup->id;
                    $newChannel->playlist_id = $newPlaylist->id;
                    $newChannel->created_at = $now;
                    $newChannel->updated_at = $now;
                    $newChannel->save();

                    // Track ID mapping for later failover translation
                    $channelIdMap[$channel->id] = $newChannel->id;
                }
            }

            // Copy the categories
            foreach ($playlist->categories()->lazy() as $category) {
                $newCategory = $category->replicate(except: [
                    'id',
                    'playlist_id',
                ]);
                $newCategory->playlist_id = $newPlaylist->id;
                $newCategory->created_at = $now;
                $newCategory->updated_at = $now;
                $newCategory->save();

                // Copy the category series
                foreach ($category->series()->lazy() as $series) {
                    $newSeries = $series->replicate(except: [
                        'id',
                        'category_id',
                        'playlist_id',
                    ]);
                    $newSeries->category_id = $newCategory->id;
                    $newSeries->playlist_id = $newPlaylist->id;
                    $newSeries->created_at = $now;
                    $newSeries->updated_at = $now;
                    $newSeries->save();

                    // Copy the series seasons
                    foreach ($series->seasons()->lazy() as $season) {
                        $newSeason = $season->replicate(except: [
                            'id',
                            'series_id',
                            'category_id',
                            'playlist_id',
                        ]);
                        $newSeason->series_id = $newSeries->id;
                        $newSeason->category_id = $newCategory->id;
                        $newSeason->playlist_id = $newPlaylist->id;
                        $newSeason->created_at = $now;
                        $newSeason->updated_at = $now;
                        $newSeason->save();

                        // Copy the season episodes
                        foreach ($season->episodes()->lazy() as $episode) {
                            $newEpisode = $episode->replicate(except: [
                                'id',
                                'season_id',
                                'series_id',
                                'playlist_id',
                            ]);
                            $newEpisode->season_id = $newSeason->id;
                            $newEpisode->series_id = $newSeries->id;
                            $newEpisode->playlist_id = $newPlaylist->id;
                            $newEpisode->created_at = $now;
                            $newEpisode->updated_at = $now;
                            $newEpisode->save();
                        }
                    }
                }
            }

            // Copy ungrouped channels
            foreach ($playlist->channels()->whereNull('group_id')->with('failovers')->lazy() as $channel) {
                $newChannel = $channel->replicate(except: [
                    'id',
                    'group_id',
                    'playlist_id',
                ]);
                $newChannel->group_id = null;
                $newChannel->playlist_id = $newPlaylist->id;
                $newChannel->created_at = $now;
                $newChannel->updated_at = $now;
                $newChannel->save();

                // Track mapping for failover translation
                $channelIdMap[$channel->id] = $newChannel->id;
            }

            // After all channels have been copied, replicate failovers
            foreach ($playlist->channels()->with('failovers')->lazy() as $channel) {
                $newChannelId = $channelIdMap[$channel->id] ?? null;
                if (! $newChannelId) {
                    logger()->info('DuplicatePlaylist: missing child channel for failover copy', [
                        'channel_id' => $channel->id,
                    ]);

                    continue;
                }

                foreach ($channel->failovers as $failover) {
                    $newFailover = $failover->replicate(except: ['id', 'channel_id']);
                    $newFailover->channel_id = $newChannelId;
                    $newFailover->channel_failover_id = $channelIdMap[$failover->channel_failover_id] ?? $failover->channel_failover_id;
                    $newFailover->created_at = $now;
                    $newFailover->updated_at = $now;
                    $newFailover->external = true;
                    $newFailover->save();
                }
            }

            // Copy uncategorized series
            foreach ($playlist->series()->whereNull('category_id')->lazy() as $series) {
                $newSeries = $series->replicate(except: ['id', 'category_id', 'playlist_id']);
                $newSeries->category_id = null;
                $newSeries->playlist_id = $newPlaylist->id;
                $newSeries->created_at = $now;
                $newSeries->updated_at = $now;
                $newSeries->save();

                foreach ($series->seasons()->lazy() as $season) {
                    $newSeason = $season->replicate(except: ['id', 'series_id', 'category_id', 'playlist_id']);
                    $newSeason->series_id = $newSeries->id;
                    $newSeason->category_id = null;
                    $newSeason->playlist_id = $newPlaylist->id;
                    $newSeason->created_at = $now;
                    $newSeason->updated_at = $now;
                    $newSeason->save();

                    foreach ($season->episodes()->lazy() as $episode) {
                        $newEpisode = $episode->replicate(except: ['id', 'season_id', 'series_id', 'playlist_id']);
                        $newEpisode->season_id = $newSeason->id;
                        $newEpisode->series_id = $newSeries->id;
                        $newEpisode->playlist_id = $newPlaylist->id;
                        $newEpisode->created_at = $now;
                        $newEpisode->updated_at = $now;
                        $newEpisode->save();
                    }
                }
            }

            // Note: Since PlaylistAuth can now only be assigned to one model at a time,
            // we skip copying auth assignments when duplicating playlists.
            // Users will need to manually assign auth to the duplicated playlist if needed.
            // This prevents conflicts with the unique constraint.

            // Copy uploaded file
            if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                Storage::disk('local')->makeDirectory($newPlaylist->folder_path);
                if (! Storage::disk('local')->copy($playlist->uploads, $newPlaylist->file_path)) {
                    throw new \RuntimeException("Failed to copy uploaded file for playlist {$playlist->id}");
                }
                $copiedPath = $newPlaylist->file_path;
                $newPlaylist->uploads = $copiedPath;
                $newPlaylist->save();
            }

            // Commit DB changes
            DB::commit();

            // Send notification
            try {
                FilamentNotification::make()
                    ->success()
                    ->title('Playlist Duplicated')
                    ->body("\"{$playlist->name}\" has been duplicated successfully.")
                    ->broadcast($playlist->user);
            } catch (\Throwable $broadcastError) {
                logger()->warning('Broadcast failed: '.$broadcastError->getMessage());
            }
            FilamentNotification::make()
                ->success()
                ->title('Playlist Duplicated')
                ->body("\"{$playlist->name}\" has been duplicated successfully, new playlist: \"{$newPlaylist->name}\"")
                ->sendToDatabase($playlist->user);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($copiedPath && Storage::disk('local')->exists($copiedPath)) {
                Storage::disk('local')->delete($copiedPath);
            }

            // Log the exception
            logger()->error("Error duplicating \"{$this->playlist->name}\": {$e->getMessage()}");

            // Send notification
            try {
                FilamentNotification::make()
                    ->danger()
                    ->title("Error duplicating \"{$this->playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($this->playlist->user);
            } catch (\Throwable $broadcastError) {
                logger()->warning('Broadcast failed: '.$broadcastError->getMessage());
            }
            FilamentNotification::make()
                ->danger()
                ->title("Error duplicating \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            throw $e;
        }
    }
}
