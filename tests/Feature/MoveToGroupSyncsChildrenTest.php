<?php

use App\Filament\Resources\ChannelResource;
use App\Filament\Resources\SeriesResource;
use App\Filament\Resources\VodResource;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('dispatches child sync when moving channels to a group', function () {
    Queue::fake();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $group = Group::factory()->for($user)->create(['playlist_id' => $parent->id]);
    $channel = Channel::factory()->for($user)->create([
        'playlist_id' => $parent->id,
        'group_id' => $group->id,
    ]);

    $bulkActions = ChannelResource::getTableBulkActions();
    $bulkActionGroup = $bulkActions[0];
    $moveAction = collect($bulkActionGroup->getActions())
        ->first(fn ($action) => $action->getName() === 'move');

    $moveAction->records(new EloquentCollection([$channel]))
        ->call(['playlist' => $parent->id, 'group' => $group->id]);

    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
});

it('dispatches child sync when moving series to a category', function () {
    Queue::fake();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $origCategory = Category::factory()->for($user)->for($parent)->create();
    $targetCategory = Category::factory()->for($user)->for($parent)->create();

    $series = Series::factory()->for($user)->for($parent)->for($origCategory)->create();

    $bulkActions = SeriesResource::getTableBulkActions();
    $bulkActionGroup = $bulkActions[0];
    $moveAction = collect($bulkActionGroup->getActions())
        ->first(fn ($action) => $action->getName() === 'move');

    $moveAction->records(new EloquentCollection([$series]))
        ->call(['category' => $targetCategory->id]);

    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
});

it('dispatches child sync when moving vod channels to a group', function () {
    Queue::fake();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $group = Group::factory()->for($user)->create(['playlist_id' => $parent->id]);
    $vod = Channel::factory()->for($user)->create([
        'playlist_id' => $parent->id,
        'group_id' => $group->id,
        'is_vod' => true,
    ]);

    $bulkActions = VodResource::getTableBulkActions();
    $bulkActionGroup = $bulkActions[0];
    $moveAction = collect($bulkActionGroup->getActions())
        ->first(fn ($action) => $action->getName() === 'move');

    $moveAction->records(new EloquentCollection([$vod]))
        ->call(['playlist' => $parent->id, 'group' => $group->id]);

    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
});

