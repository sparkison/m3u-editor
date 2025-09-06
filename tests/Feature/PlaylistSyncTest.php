<?php

use App\Jobs\DuplicatePlaylist;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use App\Events\SyncCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('duplicates ungrouped channels and series', function () {
    $playlist = Playlist::factory()->create();
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'name' => 'Parent Channel',
    ]);
    $series = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => null,
        'name' => 'Parent Series',
    ]);
    $season = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series->id,
        'category_id' => null,
    ]);
    Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series->id,
        'season_id' => $season->id,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    $child = Playlist::where('parent_id', $playlist->id)->first();
    expect($child)->not->toBeNull();
    expect($child->channels()->count())->toBe(1);
    expect($child->series()->count())->toBe(1);
});

it('syncs grouped channels using batched upserts', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Group',
        'name_internal' => 'group',
    ]);
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'One',
        'source_id' => 's1',
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'Two',
        'source_id' => 's2',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $chan1->update(['name' => 'One Renamed']);
    Channel::where('source_id', 's2')->first()->delete();

    (new SyncPlaylistChildren($playlist))->handle();

    $child->refresh();
    expect($child->channels()->count())->toBe(1);
    expect($child->channels()->first()->name)->toBe('One Renamed');
});

it('syncs category and series updates via upsert', function () {
    $playlist = Playlist::factory()->create();
    $category = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Cat',
        'name_internal' => 'cat',
    ]);
    $series1 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $category->id,
        'name' => 'Series One',
        'source_series_id' => 'ser1',
    ]);
    Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $category->id,
        'name' => 'Series Two',
        'source_series_id' => 'ser2',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $series1->update(['name' => 'Series One Renamed']);
    Series::where('source_series_id', 'ser2')->first()->delete();

    (new SyncPlaylistChildren($playlist))->handle();

    $child->refresh();
    expect($child->series()->count())->toBe(1);
    expect($child->series()->first()->name)->toBe('Series One Renamed');
});

it('assigns fallback source ids when duplicating channels', function () {
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => null,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();
    $childChannel = $child->channels()->first();
    expect($childChannel->source_id)->toBe('ch-' . $channel->id);

    (new SyncPlaylistChildren($playlist))->handle();
    expect($child->channels()->count())->toBe(1);
    expect($child->channels()->first()->source_id)->toBe('ch-' . $channel->id);
});

it('syncs changes for ungrouped channels to child', function () {
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'name' => 'Original',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $channel->update(['name' => 'Updated']);
    (new SyncPlaylistChildren($playlist))->handle();

    $childChannel = $child->channels()->first();
    expect($childChannel->name)->toBe('Updated');
});

it('syncs multiple custom channels without source ids', function () {
    $playlist = Playlist::factory()->create();
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => null,
        'name' => 'One',
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => null,
        'name' => 'Two',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $chan1->update(['name' => 'Renamed']);
    (new SyncPlaylistChildren($playlist))->handle();

    expect($child->channels()->count())->toBe(2);
    expect($child->channels()->where('name', 'Renamed')->exists())->toBeTrue();
    expect($child->channels()->where('name', 'Two')->exists())->toBeTrue();
});

it('prevents duplicating a child playlist', function () {
    $playlist = Playlist::factory()->create();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    (new DuplicatePlaylist($child))->handle();
})->throws(InvalidArgumentException::class);

it('syncs uploaded files to child playlists', function () {
    Storage::fake('local');
    $playlist = Playlist::factory()->create();
    Storage::disk('local')->put($playlist->file_path, 'parent');
    $playlist->update(['uploads' => $playlist->file_path]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();
    expect(Storage::disk('local')->exists($child->uploads))->toBeTrue();

    Storage::disk('local')->put($playlist->file_path, 'updated');
    (new SyncPlaylistChildren($playlist))->handle();
    expect(Storage::disk('local')->get($child->uploads))->toBe('updated');
});

it('syncs multiple custom series without source ids', function () {
    $playlist = Playlist::factory()->create();
    $series1 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => null,
        'source_series_id' => null,
        'name' => 'First',
    ]);
    Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => null,
        'source_series_id' => null,
        'name' => 'Second',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $series1->update(['name' => 'Updated First']);
    (new SyncPlaylistChildren($playlist))->handle();

    expect($child->series()->count())->toBe(2);
    expect($child->series()->where('name', 'Updated First')->exists())->toBeTrue();
    expect($child->series()->where('name', 'Second')->exists())->toBeTrue();
});

it('syncs multiple custom categories, seasons, and episodes without source ids', function () {
    $playlist = Playlist::factory()->create();
    $cat1 = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'source_category_id' => null,
        'name' => 'Cat One',
        'name_internal' => 'cat-one',
    ]);
    $series1 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $cat1->id,
        'source_series_id' => null,
        'name' => 'Series One',
    ]);
    $season1 = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series1->id,
        'category_id' => $cat1->id,
        'source_season_id' => null,
        'name' => 'Season One',
    ]);
    Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series1->id,
        'season_id' => $season1->id,
        'source_episode_id' => null,
        'title' => 'Episode One',
    ]);

    $cat2 = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'source_category_id' => null,
        'name' => 'Cat Two',
        'name_internal' => 'cat-two',
    ]);
    $series2 = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $cat2->id,
        'source_series_id' => null,
        'name' => 'Series Two',
    ]);
    $season2 = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series2->id,
        'category_id' => $cat2->id,
        'source_season_id' => null,
        'name' => 'Season Two',
    ]);
    Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'series_id' => $series2->id,
        'season_id' => $season2->id,
        'source_episode_id' => null,
        'title' => 'Episode Two',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $cat1->update(['name' => 'Cat One Renamed', 'name_internal' => 'cat-one-renamed']);
    $season1->update(['name' => 'Season One Renamed']);
    Episode::where('season_id', $season1->id)->first()->update(['title' => 'Episode One Renamed']);

    (new SyncPlaylistChildren($playlist))->handle();

    expect($child->categories()->count())->toBe(2);
    expect($child->categories()->where('name', 'Cat One Renamed')->exists())->toBeTrue();
    expect($child->categories()->where('name', 'Cat Two')->exists())->toBeTrue();

    expect($child->seasons()->count())->toBe(2);
    expect($child->seasons()->where('name', 'Season One Renamed')->exists())->toBeTrue();
    expect($child->seasons()->where('name', 'Season Two')->exists())->toBeTrue();

    expect($child->episodes()->count())->toBe(2);
    expect($child->episodes()->where('title', 'Episode One Renamed')->exists())->toBeTrue();
    expect($child->episodes()->where('title', 'Episode Two')->exists())->toBeTrue();
});

it('stops syncing after child is unsynced', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Parent Group',
        'name_internal' => 'parent-group',
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'Original',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();
    $child->update(['parent_id' => null]);

    $group->update(['name' => 'Renamed', 'name_internal' => 'renamed']);
    (new SyncPlaylistChildren($playlist))->handle();

    $childGroup = $child->groups()->first();
    expect($childGroup->name)->toBe('Parent Group');
});

it('renames groups on child playlists', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Parent Group',
        'name_internal' => 'parent-group',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $group->update(['name' => 'Renamed', 'name_internal' => 'renamed']);
    (new SyncPlaylistChildren($playlist))->handle();

    $childGroup = $child->groups()->first();
    expect($childGroup->name)->toBe('Renamed');
});

it('removes old group entry after parent rename', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Original',
        'name_internal' => 'original',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $group->update(['name' => 'Updated', 'name_internal' => 'updated']);
    (new SyncPlaylistChildren($playlist))->handle();

    $child->refresh();
    expect($child->groups()->count())->toBe(1);
    expect($child->groups()->first()->name)->toBe('Updated');
});

it('renames categories on child playlists', function () {
    $playlist = Playlist::factory()->create();
    $category = \App\Models\Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Parent Category',
        'name_internal' => 'parent-category',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $category->update(['name' => 'Renamed Category', 'name_internal' => 'renamed-category']);
    (new SyncPlaylistChildren($playlist))->handle();

    $childCategory = $child->categories()->first();
    expect($childCategory->name)->toBe('Renamed Category');
});

it('renames series on child playlists', function () {
    $playlist = Playlist::factory()->create();
    $category = \App\Models\Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);
    $series = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'category_id' => $category->id,
        'name' => 'Parent Series',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $series->update(['name' => 'Renamed Series']);
    (new SyncPlaylistChildren($playlist))->handle();

    $childSeries = $child->series()->first();
    expect($childSeries->name)->toBe('Renamed Series');
});

it('preserves child timestamps during sync', function () {
    $playlist = Playlist::factory()->create();
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'name' => 'Original',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();
    $childChannel = $child->channels()->first();
    $created = $childChannel->created_at;
    $updated = $childChannel->updated_at;

    sleep(1);
    (new SyncPlaylistChildren($playlist))->handle();
    $childChannel->refresh();

    expect($childChannel->created_at)->toBe($created);
    expect($childChannel->updated_at)->toBe($updated);
});

it('propagates rapid channel updates to child in a single job', function () {
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    Queue::fake();

    $channel->update(['name' => 'First']);
    $channel->update(['name' => 'Second']);

    Queue::assertPushed(SyncPlaylistChildren::class, 1);

    (new SyncPlaylistChildren($playlist))->handle();

    $childChannel = $child->channels()->first();
    expect($childChannel->name)->toBe('Second');
});

it('dispatches a single sync job when group updated', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Parent Group',
        'name_internal' => 'parent-group',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    Queue::fake();

    $group->update(['name' => 'Renamed', 'name_internal' => 'renamed']);
    $group->update(['name' => 'Again', 'name_internal' => 'again']);
    Queue::assertPushed(SyncPlaylistChildren::class, 1);
});

it('dispatches child sync after parent provider sync', function () {
    $playlist = Playlist::factory()->create();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    Queue::fake();
    event(new SyncCompleted($playlist));
    Queue::assertPushed(SyncPlaylistChildren::class, 1);
});

it('dispatches a single sync job for rapid parent saves', function () {
    $playlist = Playlist::factory()->create();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    Queue::fake();
    $playlist->update(['name' => 'first']);
    $playlist->update(['description' => 'second']);

    Queue::assertPushed(SyncPlaylistChildren::class, 1);
});

it('does not dispatch sync job when only renaming parent playlist', function () {
    $playlist = Playlist::factory()->create();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    Queue::fake();
    $playlist->update(['name' => 'Renamed Name']);

    Queue::assertNothingPushed();
});

it('blocks provider sync for child playlists', function () {
    $playlist = Playlist::factory()->create();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $response = $this->get("/playlist/{$child->uuid}/sync");
    $response->assertStatus(403);
});

it('rolls back playlist duplication when file copy fails', function () {
    Event::fake();
    Notification::fake();

    $playlist = Playlist::factory()->create([
        'uploads' => 'parent.m3u',
    ]);

    Storage::shouldReceive('disk')->with('local')->andReturnSelf()->byDefault();
    Storage::shouldReceive('exists')->andReturnTrue();
    Storage::shouldReceive('makeDirectory')->andReturnTrue();
    Storage::shouldReceive('copy')->andReturnFalse();

    expect(fn() => (new DuplicatePlaylist($playlist, withSync: true))->handle())
        ->toThrow(\RuntimeException::class);

    expect(Playlist::count())->toBe(1);
});

it('removes copied file when duplication fails after copy', function () {
    Event::fake();
    Notification::fake();

    Storage::fake('local');
    Storage::disk('local')->put('parent.m3u', '#EXTM3U');

    $playlist = Playlist::factory()->create([
        'uploads' => 'parent.m3u',
    ]);

    $childUuid = (string) Str::uuid();
    Str::createUuidsUsing(fn () => $childUuid);

    Event::listen('eloquent.saving: ' . Playlist::class, function (Playlist $model) use ($childUuid) {
        if ($model->uuid === $childUuid && $model->uploads) {
            throw new \RuntimeException('fail after copy');
        }
    });

    expect(fn () => (new DuplicatePlaylist($playlist, withSync: true))->handle())
        ->toThrow(\RuntimeException::class);

    Event::forget('eloquent.saving: ' . Playlist::class);
    Str::createUuidsNormally();

    Storage::disk('local')->assertMissing("playlist/{$childUuid}/playlist.m3u");
    expect(Playlist::count())->toBe(1);
});

it('aborts child sync when file copy fails', function () {
    Event::fake();
    Notification::fake();

    Storage::shouldReceive('disk')->with('local')->andReturnSelf()->byDefault();
    Storage::shouldReceive('exists')->andReturnTrue()->byDefault();
    Storage::shouldReceive('makeDirectory')->andReturnTrue()->byDefault();
    Storage::shouldReceive('copy')->andReturnFalse();

    Queue::fake();

    $parent = Playlist::factory()->create([
        'uploads' => 'parent.m3u',
    ]);
    $child = Playlist::factory()->create([
        'parent_id' => $parent->id,
        'uploads' => null,
    ]);

    Group::factory()->create([
        'playlist_id' => $parent->id,
        'user_id' => $parent->user_id,
        'name' => 'Parent Group',
        'name_internal' => 'parent-group',
    ]);

    expect(fn() => (new SyncPlaylistChildren($parent))->handle())
        ->toThrow(\RuntimeException::class);

    $child->refresh();
    expect($child->groups)->toBeEmpty();
});

it('processes many child playlists without excessive memory', function () {
    config()->set('cache.default', 'array');
    $parent = Playlist::factory()->create();
    Channel::factory()->create([
        'playlist_id' => $parent->id,
        'user_id' => $parent->user_id,
        'name' => 'Parent Channel',
    ]);
    Playlist::factory()->count(150)->create([
        'parent_id' => $parent->id,
    ]);

    $before = memory_get_usage(true);
    (new SyncPlaylistChildren($parent))->handle();
    $after = memory_get_usage(true);

    expect($parent->children()->count())->toBe(150);
    expect($after - $before)->toBeLessThan(20 * 1024 * 1024);
});

it('clears queued flag when sync job fails', function () {
    config()->set('cache.default', 'array');
    $parent = Playlist::factory()->create();
    Playlist::factory()->create([
        'parent_id' => $parent->id,
    ]);

    Cache::add("playlist-sync:{$parent->id}:queued", true, 5);

    /** @var \App\Jobs\SyncPlaylistChildren|\Mockery\MockInterface $job */
    $job = Mockery::mock(SyncPlaylistChildren::class, [$parent, ['groups' => ['g']]])->makePartial();
    $job->shouldReceive('syncDelta')->andThrow(new \RuntimeException('boom'));

    expect(fn() => $job->handle())->toThrow(\RuntimeException::class);

    expect(Cache::has("playlist-sync:{$parent->id}:queued"))->toBeFalse();

    Queue::fake();
    SyncPlaylistChildren::debounce($parent, ['groups' => ['g']]);
    Queue::assertPushed(SyncPlaylistChildren::class, 1);
});
