<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Playlist;
use App\Services\XtreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesChunk implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public array    $series,
        public int      $catId,
        public int      $count,
        public Category $category,
        public string   $batchNo,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        // Init the Xtream API
        $xtream = $xtream->init($this->playlist);

        // See if the category exists
        $playlistCategory = $this->category;

        // Get the series info from the Xtream API
        $seriesId = $this->series['series_id'];
        $seriesName = $this->series['name'];

        // Check if the series exists in the playlist
        $playlistSeries = $this->playlist->series()
            ->where('source_series_id', $seriesId)
            ->first();

        // If the series doesn't exist, create it
        if (!$playlistSeries) {
            $playlistSeries = $this->playlist->series()->create([
                'enabled' => true, // Enable the series by default
                'name' => $seriesName,
                'source_series_id' => $seriesId,
                'source_category_id' => $this->catId,
                'import_batch_no' => $this->batchNo,
                'user_id' => $this->playlist->user_id,
                'playlist_id' => $this->playlist->id,
                'category_id' => $playlistCategory->id,
                'sort' => $this->series['num'] ?? null,
                'cover' => $this->series['cover'] ?? null,
                'plot' => $this->series['plot'] ?? null,
                'genre' => $this->series['genre'] ?? null,
                'release_date' => $this->series['releaseDate'] ?? null,
                'cast' => $this->series['cast'] ?? null,
                'director' => $this->series['director'],
                'rating' => $this->series['rating'] ?? null,
                'rating_5based' => (float) $this->series['rating_5based'] ?? null,
                'backdrop_path' => $this->series['backdrop_path'] ?? null,
                'youtube_trailer' => $this->series['youtube_trailer'] ?? null,
            ]);
        } else {
            // Update the series if it exists
            $playlistSeries->update([
                'name' => $seriesName,
                'new' => false,
                'source_category_id' => $this->catId,
                'import_batch_no' => $this->batchNo,
            ]);
        }

        // Update the series progress
        $this->playlist->update([
            'series_progress' => min(99, $this->playlist->series_progress + (100 / $this->count)),
        ]);
    }
}
