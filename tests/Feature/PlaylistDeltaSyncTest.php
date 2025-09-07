<?php

use App\Jobs\DuplicatePlaylist;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Models\Category;
use App\Models\Series;
use App\Models\Season;
use App\Models\Episode;
use App\Models\Playlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

function createSyncedPair(): array {
    $playlist = Playlist::factory()->create();
    $groupA = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'A',
        'name_internal' => 'a',
    ]);
    $groupB = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'B',
        'name_internal' => 'b',
    ]);
    $ch1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $groupA->id,
        'name' => 'One',
        'source_id' => null,
    ]);
    $ch2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $groupA->id,
        'name' => 'Two',
        'source_id' => null,
    ]);

    $catA = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'CatA',
        'source_category_id' => null,
    ]);
    $catB = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'CatB',
        'source_category_id' => null,
    ]);
    $series1 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $catA->id,
        'name' => 'Series1',
        'source_series_id' => null,
    ]);
    $series2 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $catA->id,
        'name' => 'Series2',
        'source_series_id' => null,
    ]);

    $season = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $catA->id,
        'series_id' => $series1->id,
        'name' => 'Season1',
        'season_number' => 1,
        'source_season_id' => null,
    ]);

    $episode = Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series1->id,
        'season_id' => $season->id,
        'title' => 'Ep1',
        'episode_num' => 1,
        'season' => 1,
        'source_episode_id' => null,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    return [$playlist, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2, $season, $episode];
}

it('renames a channel without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();

    $childCh2 = $child->channels()->where('source_id', 'ch-' . $ch2->id)->first();
    $oldUpdated = $childCh2->updated_at;

    $ch1->update(['name' => 'Uno']);
    SyncPlaylistChildren::debounce($parent, ['channels' => ['ch-' . $ch1->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->channels()->where('source_id', 'ch-' . $ch1->id)->first()->name)->toBe('Uno');
    $childCh2->refresh();
    expect($childCh2->updated_at)->toEqual($oldUpdated);
});

it('moves a channel to a different group', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();
    $childCh2 = $child->channels()->where('source_id', 'ch-' . $ch2->id)->first();
    $oldUpdated = $childCh2->updated_at;

    $ch1->update(['group_id' => $groupB->id]);
    SyncPlaylistChildren::debounce($parent, ['channels' => ['ch-' . $ch1->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    $childGroupB = $child->groups()->where('name_internal', 'b')->first();
    expect($child->channels()->where('source_id', 'ch-' . $ch1->id)->first()->group_id)->toBe($childGroupB->id);
    $childCh2->refresh();
    expect($childCh2->updated_at)->toEqual($oldUpdated);
});

it('deletes a channel without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();
    $childCh2 = $child->channels()->where('source_id', 'ch-' . $ch2->id)->first();
    $oldUpdated = $childCh2->updated_at;

    $source = 'ch-' . $ch1->id;
    $ch1->delete();
    SyncPlaylistChildren::debounce($parent, ['channels' => [$source]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->channels()->where('source_id', $source)->exists())->toBeFalse();
    $childCh2->refresh();
    expect($childCh2->updated_at)->toEqual($oldUpdated);
});

it('keeps child failover reference after a channel update', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'G',
        'name_internal' => 'g',
    ]);
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'One',
        'source_id' => null,
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'Two',
        'source_id' => null,
    ]);

    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
        'sort' => 0,
        'metadata' => [],
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $chan1->update(['name' => 'Uno']);
    SyncPlaylistChildren::debounce($playlist, ['channels' => ['ch-' . $chan1->id]]);
    (new SyncPlaylistChildren($playlist))->handle();

    $childChan1 = $child->channels()->where('source_id', 'ch-' . $chan1->id)->first();
    $childChan2 = $child->channels()->where('source_id', 'ch-' . $chan2->id)->first();

    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('renames a category without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB] = createSyncedPair();

    $childCatB = $child->categories()->where('source_category_id', 'cat-' . $catB->id)->first();
    $oldUpdated = $childCatB->updated_at;

    $catA->update(['name' => 'CatOne']);
    SyncPlaylistChildren::debounce($parent, ['categories' => ['cat-' . $catA->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->categories()->where('source_category_id', 'cat-' . $catA->id)->first()->name)->toBe('CatOne');
    $childCatB->refresh();
    expect($childCatB->updated_at)->toEqual($oldUpdated);
});

it('renames a series without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2] = createSyncedPair();

    $childSeries2 = $child->series()->where('source_series_id', 'series-' . $series2->id)->first();
    $oldUpdated = $childSeries2->updated_at;

    $series1->update(['name' => 'S1']);
    SyncPlaylistChildren::debounce($parent, ['series' => ['series-' . $series1->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->series()->where('source_series_id', 'series-' . $series1->id)->first()->name)->toBe('S1');
    $childSeries2->refresh();
    expect($childSeries2->updated_at)->toEqual($oldUpdated);
});

it('renames a season without touching its episodes', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2, $season, $episode] = createSyncedPair();

    $childEpisode = $child->episodes()->where('source_episode_id', 'ep-' . $episode->id)->first();
    $oldUpdated = $childEpisode->updated_at;

    $season->update(['name' => 'Season Uno']);
    SyncPlaylistChildren::debounce($parent, ['seasons' => ['season-' . $season->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->seasons()->where('source_season_id', 'season-' . $season->id)->first()->name)->toBe('Season Uno');
    $childEpisode->refresh();
    expect($childEpisode->updated_at)->toEqual($oldUpdated);
});

it('renames an episode without touching its season', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2, $season, $episode] = createSyncedPair();

    $childSeason = $child->seasons()->where('source_season_id', 'season-' . $season->id)->first();
    $oldUpdated = $childSeason->updated_at;

    $episode->update(['title' => 'Episode Uno']);
    SyncPlaylistChildren::debounce($parent, ['episodes' => ['ep-' . $episode->id]]);
    (new SyncPlaylistChildren($parent))->handle();

    expect($child->episodes()->where('source_episode_id', 'ep-' . $episode->id)->first()->title)->toBe('Episode Uno');
    $childSeason->refresh();
    expect($childSeason->updated_at)->toEqual($oldUpdated);
});

it('coalesces multiple channel renames into one job', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();
    Queue::fake();

    $ch1->update(['name' => 'Uno']);
    $ch2->update(['name' => 'Dos']);

    Queue::assertPushed(SyncPlaylistChildren::class, 1);

    (new SyncPlaylistChildren($parent))->handle();

    expect($child->channels()->where('source_id', 'ch-' . $ch1->id)->first()->name)->toBe('Uno');
    expect($child->channels()->where('source_id', 'ch-' . $ch2->id)->first()->name)->toBe('Dos');
});

it('coalesces multiple group renames into one job', function () {
    [$parent, $child, $groupA, $groupB] = createSyncedPair();
    Queue::fake();

    $groupA->update(['name' => 'Group A']);
    $groupB->update(['name' => 'Group B']);

    Queue::assertPushed(SyncPlaylistChildren::class, 1);

    (new SyncPlaylistChildren($parent))->handle();

    expect($child->groups()->where('name_internal', 'a')->first()->name)->toBe('Group A');
    expect($child->groups()->where('name_internal', 'b')->first()->name)->toBe('Group B');
});
