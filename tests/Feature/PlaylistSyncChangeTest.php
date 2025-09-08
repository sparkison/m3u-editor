<?php

use App\Jobs\SyncPlaylistChildren;
use App\Models\Playlist;
use App\Models\PlaylistSyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['cache.default' => 'array', 'broadcasting.default' => 'log']);
});

it('merges duplicate playlist sync change records', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    SyncPlaylistChildren::debounce($playlist, ['channels' => ['a', 'b']]);
    SyncPlaylistChildren::debounce($playlist, ['channels' => ['b', 'c']]);

    $change = PlaylistSyncChange::where('playlist_id', $playlist->id)
        ->where('change_type', 'channels')
        ->first();

    expect(PlaylistSyncChange::count())->toBe(1);
    expect($change->item_ids)->toEqualCanonicalizing(['a', 'b', 'c']);
});
