<?php

use App\Jobs\DuplicatePlaylist;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Category;
use App\Models\Series;
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

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    return [$playlist, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2];
}

it('renames a channel without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();

    $childCh2 = $child->channels()->where('source_id', 'ch-' . $ch2->id)->first();
    $oldUpdated = $childCh2->updated_at;

    $ch1->update(['name' => 'Uno']);
    (new SyncPlaylistChildren($parent, ['channels' => ['ch-' . $ch1->id]]))->handle();

    expect($child->channels()->where('source_id', 'ch-' . $ch1->id)->first()->name)->toBe('Uno');
    $childCh2->refresh();
    expect($childCh2->updated_at)->toEqual($oldUpdated);
});

it('moves a channel to a different group', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2] = createSyncedPair();
    $childCh2 = $child->channels()->where('source_id', 'ch-' . $ch2->id)->first();
    $oldUpdated = $childCh2->updated_at;

    $ch1->update(['group_id' => $groupB->id]);
    (new SyncPlaylistChildren($parent, ['channels' => ['ch-' . $ch1->id]]))->handle();

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
    (new SyncPlaylistChildren($parent, ['channels' => [$source]]))->handle();

    expect($child->channels()->where('source_id', $source)->exists())->toBeFalse();
    $childCh2->refresh();
    expect($childCh2->updated_at)->toEqual($oldUpdated);
});

it('renames a category without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB] = createSyncedPair();

    $childCatB = $child->categories()->where('source_category_id', 'cat-' . $catB->id)->first();
    $oldUpdated = $childCatB->updated_at;

    $catA->update(['name' => 'CatOne']);
    (new SyncPlaylistChildren($parent, ['categories' => ['cat-' . $catA->id]]))->handle();

    expect($child->categories()->where('source_category_id', 'cat-' . $catA->id)->first()->name)->toBe('CatOne');
    $childCatB->refresh();
    expect($childCatB->updated_at)->toEqual($oldUpdated);
});

it('renames a series without touching others', function () {
    [$parent, $child, $groupA, $groupB, $ch1, $ch2, $catA, $catB, $series1, $series2] = createSyncedPair();

    $childSeries2 = $child->series()->where('source_series_id', 'series-' . $series2->id)->first();
    $oldUpdated = $childSeries2->updated_at;

    $series1->update(['name' => 'S1']);
    (new SyncPlaylistChildren($parent, ['series' => ['series-' . $series1->id]]))->handle();

    expect($child->series()->where('source_series_id', 'series-' . $series1->id)->first()->name)->toBe('S1');
    $childSeries2->refresh();
    expect($childSeries2->updated_at)->toEqual($oldUpdated);
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
