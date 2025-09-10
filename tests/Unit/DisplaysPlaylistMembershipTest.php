<?php

use App\Models\User;
use App\Models\Playlist;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Concerns\DisplaysPlaylistMembership;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class DisplaysPlaylistMembershipTestHelper
{
    use DisplaysPlaylistMembership;

    public static function names(Model $record, string $sourceKey)
    {
        return self::getPlaylistNames($record, $sourceKey);
    }

    public static function display(Model $record, string $sourceKey)
    {
        return self::playlistDisplay($record, $sourceKey);
    }
}

it('lists parent playlists before children', function () {
    $user = User::factory()->create();

    $parent = Playlist::factory()->for($user)->create(['name' => 'Parent']);
    $child = Playlist::factory()->for($user)->create([
        'name' => 'Child',
        'parent_id' => $parent->id,
    ]);

    Group::withoutEvents(fn() => Group::factory()->for($user)->for($parent)->create(['name_internal' => 'group']));
    $group = Group::withoutEvents(fn() => Group::factory()->for($user)->for($child)->create(['name_internal' => 'group']));

    $names = DisplaysPlaylistMembershipTestHelper::names($group, 'name_internal');

    expect($names->all())->toBe(['Parent', 'Child']);
    expect(DisplaysPlaylistMembershipTestHelper::display($group, 'name_internal'))->toBe('Parent +1');
});
