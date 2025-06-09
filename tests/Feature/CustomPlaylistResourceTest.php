<?php

use App\Filament\Resources\CustomPlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\User;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\ChannelFailover;
use function Pest\Livewire\livewire;
use Filament\Tables\Actions\AttachAction;

// Standard setup for acting as a user
beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

test('failover count is displayed correctly in ChannelsRelationManager', function () {
    // Arrange
    $playlist = Playlist::factory()->for($this->user)->create(['name' => 'Original PL']);
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $channelWithFailovers = Channel::factory()->for($this->user)->for($playlist)->create();
    $channelWithoutFailovers = Channel::factory()->for($this->user)->for($playlist)->create();

    $customPlaylist->channels()->attach([$channelWithFailovers->id, $channelWithoutFailovers->id]);

    // Create failovers for the first channel
    // Make sure ChannelFailoverFactory links to a valid channel_failover_id if not creating one.
    // For this test, it's simpler if the factory handles creating a distinct channel for channel_failover_id.
    // Or, create another channel specifically for the failover.
    $failoverTargetChannel = Channel::factory()->for($this->user)->for($playlist)->create();
    ChannelFailover::factory()->for($this->user)->count(2)->create([
        'channel_id' => $channelWithFailovers->id,
        'channel_failover_id' => $failoverTargetChannel->id,
        // If factory creates distinct failovers, this specific ID might not be needed here,
        // but ensuring channel_failover_id is valid.
    ]);

    // Act & Assert
    livewire(CustomPlaylistResource\RelationManagers\ChannelsRelationManager::class, [
        'ownerRecord' => $customPlaylist,
        'pageClass' => CustomPlaylistResource\Pages\EditCustomPlaylist::class,
    ])
    ->assertCanSeeTableRecords($customPlaylist->channels()->get()) // ensure we pass collection
    ->assertCountTableRecords(2)
    ->assertTableColumnHasState('failovers_count', '2', $channelWithFailovers)
    ->assertTableColumnHasState('failovers_count', '0', $channelWithoutFailovers);

});
