<?php

use App\Jobs\DuplicatePlaylist;
use App\Jobs\SyncPlaylistChildren;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uImportSeriesComplete;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use App\Events\SyncCompleted;
use App\Enums\Status;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

it('remaps failover ids during playlist duplication', function () {
    $playlist = Playlist::factory()->create();
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'f1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'f2',
    ]);
    ChannelFailover::factory()->create([
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    $child = Playlist::where('parent_id', $playlist->id)->first();
    $childChan1 = $child->channels()->where('source_id', 'f1')->first();
    $childChan2 = $child->channels()->where('source_id', 'f2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('remaps cross-group failovers during playlist duplication', function () {
    $playlist = Playlist::factory()->create();
    $groupA = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'ga',
    ]);
    $groupB = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'gb',
    ]);
    $chanA = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $groupA->id,
        'source_id' => 'ga1',
    ]);
    $chanB = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $groupB->id,
        'source_id' => 'gb1',
    ]);
    ChannelFailover::factory()->create([
        'channel_id' => $chanA->id,
        'channel_failover_id' => $chanB->id,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();

    $child = Playlist::where('parent_id', $playlist->id)->first();
    $childChanA = $child->channels()->where('source_id', 'ga1')->first();
    $childChanB = $child->channels()->where('source_id', 'gb1')->first();
    expect($childChanA->failovers()->first()->channel_failover_id)->toBe($childChanB->id);
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

it('syncs groups without internal names using fallback keys', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Group Name',
        'name_internal' => null,
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'name' => 'Chan',
    ]);

    $child = Playlist::factory()->create([
        'parent_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    config(['cache.default' => 'array']);
    Log::spy();
    Playlist::unguard();
    (new SyncPlaylistChildren($playlist))->handle();
    Playlist::reguard();

    $child->refresh();
    expect($child->groups()->count())->toBe(1);
    Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($msg) => str_contains($msg, 'Child group not found'))]);
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

it('maps grouped channel failovers to child channels', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'grp',
    ]);
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'source_id' => 's1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'source_id' => 's2',
    ]);
    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
        'sort' => 0,
        'metadata' => [],
    ]);

    Notification::fake();
    Config::set('cache.default', 'array');
    Playlist::unguard();
    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    DB::enableQueryLog();
    DB::flushQueryLog();
    (new SyncPlaylistChildren($playlist))->handle();
    $queries = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'from "channels"') && str_contains($q['query'], 'where "source_id" ='))
        ->count();
    expect($queries)->toBe(0);

    $childChan1 = $child->channels()->where('source_id', 's1')->first();
    $childChan2 = $child->channels()->where('source_id', 's2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('maps ungrouped channel failovers to child channels', function () {
    $playlist = Playlist::factory()->create();
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'group' => null,
        'source_id' => 'p1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'group' => null,
        'source_id' => 'p2',
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

    (new SyncPlaylistChildren($playlist))->handle();

    $childChan1 = $child->channels()->where('source_id', 'p1')->first();
    $childChan2 = $child->channels()->where('source_id', 'p2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('maps failovers across different groups to child channels', function () {
    $playlist = Playlist::factory()->create();
    $group1 = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'g1',
    ]);
    $group2 = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'g2',
    ]);
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group1->id,
        'source_id' => 'cg1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group2->id,
        'source_id' => 'cg2',
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

    (new SyncPlaylistChildren($playlist))->handle();

    $childChan1 = $child->channels()->where('source_id', 'cg1')->first();
    $childChan2 = $child->channels()->where('source_id', 'cg2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('maps grouped failovers targeting ungrouped channels', function () {
    $playlist = Playlist::factory()->create();
    $group = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name_internal' => 'grp',
    ]);
    $grouped = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'source_id' => 'gx',
    ]);
    $ungrouped = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'ux',
    ]);
    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $grouped->id,
        'channel_failover_id' => $ungrouped->id,
        'sort' => 0,
        'metadata' => [],
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    (new SyncPlaylistChildren($playlist))->handle();

    $childGrouped = $child->channels()->where('source_id', 'gx')->first();
    $childUngrouped = $child->channels()->where('source_id', 'ux')->first();
    expect($childGrouped->failovers()->first()->channel_failover_id)->toBe($childUngrouped->id);
});

it('maps delta-synced failovers to child channels', function () {
    $playlist = Playlist::factory()->create();
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'd1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'd2',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
        'sort' => 0,
        'metadata' => [],
    ]);

    (new SyncPlaylistChildren($playlist, ['channels' => ['d1']]))->handle();

    $childChan1 = $child->channels()->where('source_id', 'd1')->first();
    $childChan2 = $child->channels()->where('source_id', 'd2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('maps mutually referenced delta failovers to child channels', function () {
    Notification::fake();
    Config::set('cache.default', 'array');

    $playlist = Playlist::factory()->create();
    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'dx1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'dx2',
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
        'sort' => 0,
        'metadata' => [],
    ]);
    ChannelFailover::create([
        'user_id' => $playlist->user_id,
        'channel_id' => $chan2->id,
        'channel_failover_id' => $chan1->id,
        'sort' => 0,
        'metadata' => [],
    ]);

    (new SyncPlaylistChildren($playlist, ['channels' => ['dx1', 'dx2']]))->handle();

    $childChan1 = $child->channels()->where('source_id', 'dx1')->first();
    $childChan2 = $child->channels()->where('source_id', 'dx2')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
    expect($childChan2->failovers()->first()->channel_failover_id)->toBe($childChan1->id);
});

it('preserves failover channels from external playlists', function () {
    $parent = Playlist::factory()->create();
    $external = Playlist::factory()->create();

    $externalChannel = Channel::factory()->create([
        'playlist_id' => $external->id,
        'user_id' => $external->user_id,
        'group_id' => null,
        'name' => 'External',
        'source_id' => 'ext',
    ]);

    $chan = Channel::factory()->create([
        'playlist_id' => $parent->id,
        'user_id' => $parent->user_id,
        'group_id' => null,
        'name' => 'Main',
        'source_id' => 'main',
    ]);

    ChannelFailover::factory()->create([
        'channel_id' => $chan->id,
        'channel_failover_id' => $externalChannel->id,
    ]);
    Log::spy();

    (new DuplicatePlaylist($parent, withSync: true))->handle();
    $child = Playlist::where('parent_id', $parent->id)->first();

    (new SyncPlaylistChildren($parent))->handle();

    Log::shouldNotHaveReceived('warning');

    $childChannel = $child->channels()->where('source_id', 'main')->first();
    expect($childChannel->failovers()->first()->channel_failover_id)->toBe($externalChannel->id);
});

it('preserves failovers referencing missing channels', function () {
    $playlist = Playlist::factory()->create();

    Config::set('cache.default', 'array');
    Notification::fake();
    Event::fake();

    $chan = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'main-missing',
    ]);

    $orphan = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
    ]);

    ChannelFailover::factory()->create([
        'channel_id' => $chan->id,
        'channel_failover_id' => $orphan->id,
    ]);

    DB::statement('PRAGMA foreign_keys = OFF');
    $missingId = $orphan->id;
    $orphan->delete();
    DB::statement('PRAGMA foreign_keys = ON');

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    Playlist::unguard();
    Log::spy();

    (new SyncPlaylistChildren($playlist))->handle();
    Playlist::reguard();

    Log::shouldHaveReceived('warning');

    $childChannel = $child->channels()->where('source_id', 'main-missing')->first();
    expect($childChannel->failovers()->first()->channel_failover_id)->toBe($missingId);
});

it('keeps parent failover ids when child failover channel is absent', function () {
    Config::set('cache.default', 'array');
    Notification::fake();
    Event::fake();

    $playlist = Playlist::factory()->create();

    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p1',
    ]);

    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p2',
    ]);

    $failover = ChannelFailover::factory()->create([
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
    ]);

    $child = Playlist::factory()->create([
        'parent_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    $childChannel = Channel::factory()->create([
        'playlist_id' => $child->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p1',
    ]);

    $pending = [[
        'channel_id' => $childChannel->id,
        'attributes' => Arr::except($failover->getAttributes(), ['id', 'channel_id', 'created_at', 'updated_at']),
        'failover_playlist_id' => $chan2->playlist_id,
        'failover_source_id' => $chan2->source_id,
    ]];

    $job = new SyncPlaylistChildren($playlist);
    $ref = new \ReflectionClass($job);
    $map = $ref->getProperty('childChannelMap');
    $map->setAccessible(true);
    $map->setValue($job, ['p1' => $childChannel->id]);

    $method = $ref->getMethod('applyFailovers');
    $method->setAccessible(true);
    $method->invoke($job, $playlist, $child, $pending);

    $childChannel->refresh();
    expect($childChannel->failovers()->first()->channel_failover_id)->toBe($chan2->id);
});

it('removes child failovers when deleted on the parent', function () {
    Config::set('cache.default', 'array');
    Notification::fake();
    Event::fake();

    $playlist = Playlist::factory()->create();

    $chan1 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p1',
    ]);
    $chan2 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p2',
    ]);
    $chan3 = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'p3',
    ]);

    ChannelFailover::factory()->create([
        'channel_id' => $chan1->id,
        'channel_failover_id' => $chan2->id,
    ]);
    ChannelFailover::factory()->create([
        'channel_id' => $chan3->id,
        'channel_failover_id' => $chan2->id,
    ]);

    (new DuplicatePlaylist($playlist, withSync: true))->handle();
    $child = Playlist::where('parent_id', $playlist->id)->first();

    $childChan1 = $child->channels()->where('source_id', 'p1')->first();
    $childChan2 = $child->channels()->where('source_id', 'p2')->first();
    $childChan3 = $child->channels()->where('source_id', 'p3')->first();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
    expect($childChan3->failovers()->first()->channel_failover_id)->toBe($childChan2->id);

    ChannelFailover::where('channel_id', $chan1->id)->delete();

    Playlist::unguard();
    (new SyncPlaylistChildren($playlist))->handle();
    Playlist::reguard();
    $child->refresh();

    $childChan1 = $child->channels()->where('source_id', 'p1')->first();
    $childChan3 = $child->channels()->where('source_id', 'p3')->first();

    expect($childChan1->failovers()->count())->toBe(0);
    expect($childChan3->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});

it('rejects self-referential failovers', function () {
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    expect(fn () => ChannelFailover::create([
        'channel_id' => $channel->id,
        'channel_failover_id' => $channel->id,
        'user_id' => $channel->user_id,
    ]))->toThrow(ValidationException::class);
});

it('rejects child playlist channels as failover targets', function () {
    Queue::fake();

    $parent = Playlist::withoutEvents(fn () => Playlist::factory()->create());
    $child = Playlist::withoutEvents(fn () => Playlist::factory()->create([
        'parent_id' => $parent->id,
        'user_id' => $parent->user_id,
    ]));

    $parentChannel = Channel::withoutEvents(fn () => Channel::factory()->create([
        'playlist_id' => $parent->id,
        'user_id' => $parent->user_id,
    ]));
    $childChannel = Channel::withoutEvents(fn () => Channel::factory()->create([
        'playlist_id' => $child->id,
        'user_id' => $child->user_id,
    ]));

    expect(fn () => ChannelFailover::create([
        'channel_id' => $parentChannel->id,
        'channel_failover_id' => $childChannel->id,
        'user_id' => $parent->user_id,
    ]))->toThrow(ValidationException::class, 'Failover channel must belong to a parent playlist.');
});

it('rejects duplicate failovers', function () {
    $playlist = Playlist::factory()->create();
    $chanA = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);
    $chanB = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    ChannelFailover::create([
        'channel_id' => $chanA->id,
        'channel_failover_id' => $chanB->id,
        'user_id' => $chanA->user_id,
    ]);

    expect(fn () => ChannelFailover::create([
        'channel_id' => $chanA->id,
        'channel_failover_id' => $chanB->id,
        'user_id' => $chanA->user_id,
    ]))->toThrow(ValidationException::class, 'This failover already exists.');
});

it('syncs many failovers without excessive memory', function () {
    Config::set('cache.default', 'array');
    Event::fake();

    $playlist = Playlist::factory()->create();
    $channels = Channel::factory()->count(200)->sequence(fn (Sequence $seq) => [
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'source_id' => 'm' . $seq->index,
    ])->create();

    foreach ($channels as $index => $channel) {
        $next = $channels[($index + 1) % $channels->count()];
        ChannelFailover::create([
            'user_id' => $playlist->user_id,
            'channel_id' => $channel->id,
            'channel_failover_id' => $next->id,
            'sort' => 0,
            'metadata' => [],
        ]);
    }

    $child = Playlist::factory()->create([
        'parent_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    Playlist::unguard();
    $startPeak = memory_get_peak_usage(true);
    (new SyncPlaylistChildren($playlist))->handle();
    $endPeak = memory_get_peak_usage(true);
    Playlist::reguard();

    expect($endPeak - $startPeak)->toBeLessThan(50 * 1024 * 1024);

    $childChannel = $child->channels()->where('source_id', 'm0')->first();
    $childNext = $child->channels()->where('source_id', 'm1')->first();
    expect($childChannel->failovers()->first()->channel_failover_id)->toBe($childNext->id);
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

it('dispatches child sync after child provider sync', function () {
    $parent = Playlist::factory()->create();
    (new DuplicatePlaylist($parent, withSync: true))->handle();
    $child = Playlist::where('parent_id', $parent->id)->first();

    Queue::fake();
    event(new SyncCompleted($child));
    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
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

it('keeps child pending until series sync completes', function () {
    Queue::fake();
    Event::fake();

    $parent = Playlist::factory()->create();
    Playlist::factory()->create([
        'parent_id' => $parent->id,
    ]);

    $parent->children()->update([
        'status' => Status::Pending,
        'processing' => true,
    ]);

    (new ProcessM3uImportComplete(
        userId: $parent->user_id,
        playlistId: $parent->id,
        batchNo: 'batch',
        start: now(),
        hasSeries: true,
    ))->handle(app(GeneralSettings::class));

    Event::assertNothingDispatched();
    Queue::assertNothingPushed();

    $child = $parent->children()->first();
    expect($child->refresh()->status)->toBe(Status::Pending);
    expect($child->processing)->toBeTrue();

    Event::forgetFakes();

    (new ProcessM3uImportSeriesComplete($parent, 'batch'))->handle();

    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
});