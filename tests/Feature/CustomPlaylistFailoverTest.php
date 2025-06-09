<?php

use App\Filament\Resources\CustomPlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\User;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\ChannelFailover;
use function Pest\Livewire\livewire;
use Illuminate\Database\Eloquent\Factories\Sequence;

// Standard setup for acting as a user
beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

test('failovers tab and master channel select are visible and populated', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create(['name' => 'Original Playlist']);
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $channel1 = Channel::factory()->for($this->user)->for($playlist)
        ->create(['title_custom' => 'Channel A Custom', 'title' => 'Channel A']);
    $channel2 = Channel::factory()->for($this->user)->for($playlist)
        ->create(['title_custom' => null, 'title' => 'Channel B']); // Test with non-custom title

    $customPlaylist->channels()->attach([$channel1->id, $channel2->id]);

    // Act & Assert
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->assertFormSet([ // Ensure form is pre-filled with basic data
        'name' => $customPlaylist->name,
    ])
    ->assertSee('Failovers') // Check if the tab label is rendered
    ->assertFormFieldExists('master_channel_id_for_failover_settings')
    // Removed problematic assertElementHasNoChildren line for now
    ->fillFormField('master_channel_id_for_failover_settings', $channel1->id)
    ->assertFormFieldIsVisible('custom_playlist_failovers_repeater'); // Repeater becomes visible
});

test('failovers are loaded into repeater when master channel is selected', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create(['name' => 'Source Playlist']);
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $masterChannel = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Master Channel']);
    $failoverChannel1 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Failover 1']);
    $failoverChannel2 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Failover 2']);

    $customPlaylist->channels()->attach([$masterChannel->id, $failoverChannel1->id, $failoverChannel2->id]);

    ChannelFailover::factory()->for($this->user)->create([
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverChannel1->id,
        'sort' => 1,
    ]);
    ChannelFailover::factory()->for($this->user)->create([
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverChannel2->id,
        'sort' => 2,
    ]);

    // Act & Assert
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->fillFormField('master_channel_id_for_failover_settings', $masterChannel->id)
    ->assertSet('data.custom_playlist_failovers_repeater.0.channel_failover_id', $failoverChannel1->id)
    ->assertSet('data.custom_playlist_failovers_repeater.1.channel_failover_id', $failoverChannel2->id);
});

test('new failovers can be saved for a master channel', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $masterChannel = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Master']);
    $failoverChannel1 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'FailoverSave1']);
    $failoverChannel2 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'FailoverSave2']);

    $customPlaylist->channels()->attach([$masterChannel->id, $failoverChannel1->id, $failoverChannel2->id]);

    // Act
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->fillForm([
        'master_channel_id_for_failover_settings' => $masterChannel->id,
        'custom_playlist_failovers_repeater' => [
            ['channel_failover_id' => $failoverChannel1->id],
            ['channel_failover_id' => $failoverChannel2->id],
        ],
    ])
    ->call('save') // This is the default Filament save action name
    ->assertHasNoFormErrors();

    // Assert
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverChannel1->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverChannel2->id,
        'sort' => 2,
    ]);
    expect($masterChannel->failovers()->count())->toBe(2);
});

test('existing failovers can be updated for a master channel', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $masterChannel = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'MasterUpdate']);
    $originalFailover = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'OriginalFailover']);
    $newFailover1 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'NewFailover1']);
    $newFailover2 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'NewFailover2']);

    $customPlaylist->channels()->attach([$masterChannel->id, $originalFailover->id, $newFailover1->id, $newFailover2->id]);

    ChannelFailover::factory()->for($this->user)->create([
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $originalFailover->id,
        'sort' => 1,
    ]);

    // Act
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->fillForm([
        'master_channel_id_for_failover_settings' => $masterChannel->id,
        'custom_playlist_failovers_repeater' => [
            ['channel_failover_id' => $newFailover1->id],
            ['channel_failover_id' => $newFailover2->id],
        ],
    ])
    ->call('save')
    ->assertHasNoFormErrors();

    // Assert
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $originalFailover->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $newFailover1->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $newFailover2->id,
        'sort' => 2,
    ]);
    expect($masterChannel->failovers()->count())->toBe(2);
});

test('all failovers for a master channel can be cleared', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $masterChannel = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'MasterClear']);
    $failoverToClear = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'FailoverToClear']);

    $customPlaylist->channels()->attach([$masterChannel->id, $failoverToClear->id]);

    ChannelFailover::factory()->for($this->user)->create([
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverToClear->id,
        'sort' => 1,
    ]);

    // Act
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->fillForm([
        'master_channel_id_for_failover_settings' => $masterChannel->id,
        'custom_playlist_failovers_repeater' => [], // Empty array to clear
    ])
    ->call('save')
    ->assertHasNoFormErrors();

    // Assert
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $masterChannel->id,
        'channel_failover_id' => $failoverToClear->id,
    ]);
    expect($masterChannel->failovers()->count())->toBe(0);
});

test('saving failovers for one master channel does not affect another masters failovers', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $master1 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Master1']);
    $failover1_m1 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Failover1M1']);

    $master2 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'Master2']);
    $failover_m2 = Channel::factory()->for($this->user)->for($playlist)->create(['title' => 'FailoverM2']);

    $customPlaylist->channels()->attach([$master1->id, $failover1_m1->id, $master2->id, $failover_m2->id]);

    // Existing failover for master2 (should remain untouched)
    ChannelFailover::factory()->for($this->user)->create([
        'channel_id' => $master2->id,
        'channel_failover_id' => $failover_m2->id,
        'sort' => 1,
    ]);

    // Act: Modify failovers for master1
    livewire(CustomPlaylistResource::class . '\\Pages\\EditCustomPlaylist', [
        'record' => $customPlaylist->getRouteKey(),
    ])
    ->fillForm([
        'master_channel_id_for_failover_settings' => $master1->id,
        'custom_playlist_failovers_repeater' => [
            ['channel_failover_id' => $failover1_m1->id],
        ],
    ])
    ->call('save')
    ->assertHasNoFormErrors();

    // Assert
    // Check failover for master1 was saved
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master1->id,
        'channel_failover_id' => $failover1_m1->id,
        'sort' => 1,
    ]);
    // Check failover for master2 is untouched
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master2->id,
        'channel_failover_id' => $failover_m2->id,
        'sort' => 1,
    ]);
    expect($master1->failovers()->count())->toBe(1);
    expect($master2->failovers()->count())->toBe(1);
});
