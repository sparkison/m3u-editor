<?php

namespace App\Jobs;

use Exception;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Playlist;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessM3uImportSeries implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 60 * 60 * 1; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool    $force = false,
        public ?bool    $isNew = false,
        public ?string  $batchNo = null,

    ) {}

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->playlist->processing) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Set the batch number
        if (!$this->batchNo) {
            $this->batchNo = Str::orderedUuid()->toString();
        }

        // Setup the Xtream service
        $xtream = $xtream->init($this->playlist);
        if (! $xtream) {
            // @TODO - handle error and notify user
            return;
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => true,
            'status' => Status::Processing,
            'errors' => null,
            'series_progress' => 0,
        ]);

        $this->importSeries($xtream);
    }

    /**
     * Import series from Xtream API.
     */
    public function importSeries(XtreamService $xtream): void
    {
        try {
            // Check if importing all series, or just selected
            $config = $this->playlist->xtream_config;
            $cats = collect($xtream->getSeriesCategories());
            if ($config['import_all_series'] ?? false) {
                $categories = $cats
                    ->map(fn($c) => $c['category_id'])
                    ->pluck('category_id')
                    ->toArray();
            } else {
                $categories = $this->playlist->xtream_config['series_categories'] ?? [];
            }

            // If categories are empty, finish and error
            if (empty($categories)) {
                // @TODO - handle error and notify user
                return;
            }

            // Determine the progress update intervals
            $total = $cats->count();
            $progressInterval = (int) ($total / 100);
            $progressInterval = max($progressInterval, 1);
            $progress = 0;

            // Get the series from the Xtream API
            $jobs = [];
            foreach ($categories as $catId) {
                // Update the progress
                $progress += $progressInterval;
                    $this->playlist->update([
                    'series_progress' => (int) (($progress / $total) * 100),
                ]);

                // Get the series for the category, and the category details
                $catId = (int) $catId;
                $series = $xtream->getSeries($catId);
                $category = $cats->where('category_id', $catId)->first();

                // Make sure there are series to process
                if (empty($series) || !$category) {
                    // No series found for category, continue to next category
                    // @TODO - notify user?
                    continue;
                }

                // See if the category exists
                $playlistCategory = $this->playlist
                    ->categories()
                    ->where('source_category_id', $catId)
                    ->first();

                // Create the category if it doesn't exist
                if (!$playlistCategory) {
                    $catName = $category['category_name'] ?? '';
                    $catName = Str::of($catName)->replace(' | ', ' - ')->trim();
                    $playlistCategory = $this->playlist
                        ->categories()->create([
                            'name' => $catName,
                            'name_internal' => $catName,
                            'user_id' => $this->playlist->user_id,
                            'playlist_id' => $this->playlist->id,
                            'source_category_id' => $catId,
                        ]);
                }

                // Process each series
                $seriesCount = count($series);
                foreach ($series as $s) {
                    $jobs[] = new ProcessM3uImportSeriesChunk(
                        playlist: $this->playlist,
                        series: $s,
                        catId: $catId,
                        count: ($total * $seriesCount) / 10,
                        category: $playlistCategory,
                        batchNo: $this->batchNo,
                    );
                }
            }
            $jobs[] = new ProcessM3uImportSeriesComplete(
                playlist: $this->playlist,
                batchNo: $this->batchNo,
            );
            $playlist = $this->playlist;
            Bus::chain($jobs)
                ->onConnection('redis') // force to use redis connection
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($playlist) {
                    $error = "Error processing series sync on \"{$playlist->name}\": {$e->getMessage()}";
                    Log::error($error);
                    Notification::make()
                        ->danger()
                        ->title("Error processing series sync on \"{$playlist->name}\"")
                        ->body('Please view your notifications for details.')
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing series sync on \"{$playlist->name}\"")
                        ->body($error)
                        ->sendToDatabase($playlist->user);
                    $playlist->update([
                        'status' => Status::Failed,
                        'synced' => now(),
                        'errors' => $error,
                        'series_progress' => 100,
                        'processing' => false,
                    ]);
                    // Fire the playlist synced event
                    event(new SyncCompleted($playlist));
                })->dispatch();
        } catch (Exception $e) {
            // Update the playlist status to error
            $error = Str::limit($e->getMessage(), 255);
            $this->playlist->update([
                'processing' => false,
                'status' => Status::Failed,
                'errors' => $error,
                'series_progress' => 0,
            ]);
            Notification::make()
                ->danger()
                ->title('Series Sync Failed')
                ->body("Playlist series sync failed for \"{$this->playlist->name}\". Error: {$error}")
                ->broadcast($this->playlist->user)
                ->sendToDatabase($this->playlist->user);
            Log::error('Series Sync Failed', [
                'playlist_id' => $this->playlist->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Fire the playlist synced event
            event(new SyncCompleted($playlist));
        }
    }
}
