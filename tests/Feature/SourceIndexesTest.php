<?php

use App\Models\{Category, Playlist, Season, Series};
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses composite indexes for hierarchy lookups', function () {
    $playlist = Playlist::factory()->create();
    $category = Category::factory()->for($playlist)->create(['source_category_id' => 123]);
    $series = Series::factory()->for($playlist)->for($category)->create(['source_series_id' => 456]);
    Season::factory()->for($playlist)->for($category)->for($series)->create(['source_season_id' => 789]);

    $planCat = DB::selectOne('EXPLAIN QUERY PLAN SELECT * FROM categories WHERE playlist_id = ? AND source_category_id = ?', [$playlist->id, 123]);
    expect($planCat->detail)->toContain('USING INDEX');

    $planSeries = DB::selectOne('EXPLAIN QUERY PLAN SELECT * FROM series WHERE playlist_id = ? AND source_series_id = ?', [$playlist->id, 456]);
    expect($planSeries->detail)->toContain('USING INDEX');

    $planSeason = DB::selectOne('EXPLAIN QUERY PLAN SELECT * FROM seasons WHERE playlist_id = ? AND source_season_id = ?', [$playlist->id, 789]);
    expect($planSeason->detail)->toContain('USING INDEX');
});
