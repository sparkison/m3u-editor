<?php

use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\{CustomPlaylist, Group, Playlist, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class TestChannel extends Model
{
    protected $table = 'channels';
    protected $guarded = [];
    public $timestamps = true;
}

class HandlesSourcePlaylistTestClass
{
    use HandlesSourcePlaylist;

    public static function data(Collection $records, string $relation, string $sourceKey)
    {
        return self::getSourcePlaylistData($records, $relation, $sourceKey);
    }

    public static function map(
        Collection $records,
        array $data,
        string $relation,
        string $sourceKey,
        string $modelClass,
        ?array $sourcePlaylistData = null
    ) {
        return self::mapRecordsToSourcePlaylist(
            $records,
            $data,
            $relation,
            $sourceKey,
            $modelClass,
            $sourcePlaylistData
        );
    }

    public static function options(
        ?int $customPlaylistId,
        array $group,
        string $relation,
        string $sourceKey
    ) {
        return self::availablePlaylistsForGroup(
            $customPlaylistId,
            $group,
            $relation,
            $sourceKey
        );
    }
}

it('consolidates all child playlists for a parent and source', function () {
    $user = User::factory()->create();
    auth()->setUser($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $groupParent = Group::factory()->for($user)->for($parent)->createQuietly();
    $groupA = Group::factory()->for($user)->for($childA)->createQuietly();
    $groupB = Group::factory()->for($user)->for($childB)->createQuietly();

    $source = 'src-1';

    TestChannel::create([
        'name' => 'p',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $parent->id,
        'group_id' => $groupParent->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name' => 'a',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $childA->id,
        'group_id' => $groupA->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name' => 'b',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $childB->id,
        'group_id' => $groupB->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = TestChannel::where('playlist_id', $parent->id)->get();

    [$groups, $needs] = HandlesSourcePlaylistTestClass::data($records, 'channels', 'source_id');

    expect($needs)->toBeTrue();
    expect($groups)->toHaveCount(1);

    $group = $groups->first();
    expect($group['playlists']->keys()->sort()->values()->all())->toEqualCanonicalizing([
        $parent->id,
        $childA->id,
        $childB->id,
    ]);
});

it('throws when conflicting playlist selections are provided for the same source', function () {
    $user = User::factory()->create();
    auth()->setUser($user);

    $parent1 = Playlist::factory()->for($user)->create();
    $child1 = Playlist::factory()->for($user)->create(['parent_id' => $parent1->id]);
    $parent2 = Playlist::factory()->for($user)->create();
    $child2 = Playlist::factory()->for($user)->create(['parent_id' => $parent2->id]);

    $gP1 = Group::factory()->for($user)->for($parent1)->createQuietly();
    $gC1 = Group::factory()->for($user)->for($child1)->createQuietly();
    $gP2 = Group::factory()->for($user)->for($parent2)->createQuietly();
    $gC2 = Group::factory()->for($user)->for($child2)->createQuietly();

    $source = 'src-1';

    TestChannel::create([
        'name' => 'p1',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $parent1->id,
        'group_id' => $gP1->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name' => 'c1',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $child1->id,
        'group_id' => $gC1->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name' => 'p2',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $parent2->id,
        'group_id' => $gP2->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name' => 'c2',
        'enabled' => true,
        'shift' => 0,
        'user_id' => $user->id,
        'playlist_id' => $child2->id,
        'group_id' => $gC2->id,
        'source_id' => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = TestChannel::where('playlist_id', $parent1->id)->get();
    $sourcePlaylistData = HandlesSourcePlaylistTestClass::data($records, 'channels', 'source_id');
    [$groups] = $sourcePlaylistData;

    $groupKeys = [];
    foreach ($groups as $key => $grp) {
        $groupKeys[$grp['parent_id']] = $key;
    }

    $data = [
        'source_playlists' => [
            $groupKeys[$parent1->id] => $child1->id,
            $groupKeys[$parent2->id] => $child2->id,
        ],
    ];

    expect(fn () => HandlesSourcePlaylistTestClass::map(
        $records,
        $data,
        'channels',
        'source_id',
        TestChannel::class,
        $sourcePlaylistData
    ))->toThrow(ValidationException::class);
});

it('filters playlists already used in the custom playlist', function () {
    $user = User::factory()->create();
    auth()->setUser($user);

    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $gParent = Group::factory()->for($user)->for($parent)->createQuietly();
    $gChild  = Group::factory()->for($user)->for($child)->createQuietly();

    $source = 'src-1';

    $parentChannel = TestChannel::create([
        'name'       => 'p',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $parent->id,
        'group_id'   => $gParent->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name'       => 'c',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $child->id,
        'group_id'   => $gChild->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = TestChannel::where('playlist_id', $parent->id)->get();
    [$groups] = HandlesSourcePlaylistTestClass::data($records, 'channels', 'source_id');
    $group = $groups->first();

    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->channels()->attach($parentChannel->id);

    $options = HandlesSourcePlaylistTestClass::options($custom->id, $group, 'channels', 'source_id');

    expect($options->keys()->all())->toEqual([$child->id]);
});

it('throws when a playlist outside the group is selected at the group level', function () {
    $user = User::factory()->create();
    auth()->setUser($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $other  = Playlist::factory()->for($user)->create();

    $gParent = Group::factory()->for($user)->for($parent)->createQuietly();
    $gChild  = Group::factory()->for($user)->for($child)->createQuietly();

    $source = 'src-1';

    TestChannel::create([
        'name'       => 'p',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $parent->id,
        'group_id'   => $gParent->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name'       => 'c',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $child->id,
        'group_id'   => $gChild->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = TestChannel::where('playlist_id', $parent->id)->get();
    $sourcePlaylistData = HandlesSourcePlaylistTestClass::data($records, 'channels', 'source_id');
    [$groups] = $sourcePlaylistData;
    $groupKey = $groups->keys()->first();

    $data = [
        'source_playlists' => [
            $groupKey => $other->id,
        ],
    ];

    expect(fn () => HandlesSourcePlaylistTestClass::map(
        $records,
        $data,
        'channels',
        'source_id',
        TestChannel::class,
        $sourcePlaylistData
    ))->toThrow(ValidationException::class);
});

it('throws when a playlist outside the group is selected for an item', function () {
    $user = User::factory()->create();
    auth()->setUser($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $other  = Playlist::factory()->for($user)->create();

    $gParent = Group::factory()->for($user)->for($parent)->createQuietly();
    $gChild  = Group::factory()->for($user)->for($child)->createQuietly();

    $source = 'src-1';

    TestChannel::create([
        'name'       => 'p',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $parent->id,
        'group_id'   => $gParent->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TestChannel::create([
        'name'       => 'c',
        'enabled'    => true,
        'shift'      => 0,
        'user_id'    => $user->id,
        'playlist_id'=> $child->id,
        'group_id'   => $gChild->id,
        'source_id'  => $source,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = TestChannel::where('playlist_id', $parent->id)->get();
    $sourcePlaylistData = HandlesSourcePlaylistTestClass::data($records, 'channels', 'source_id');
    [$groups] = $sourcePlaylistData;
    $groupKey = $groups->keys()->first();

    $data = [
        'source_playlist_items' => [
            $groupKey => [
                $source => $other->id,
            ],
        ],
    ];

    expect(fn () => HandlesSourcePlaylistTestClass::map(
        $records,
        $data,
        'channels',
        'source_id',
        TestChannel::class,
        $sourcePlaylistData
    ))->toThrow(ValidationException::class);
});

