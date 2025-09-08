<?php

use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Invade\Invader;

uses(RefreshDatabase::class);

it('does not prepopulate failover field with child playlist channels', function () {
    $user = User::factory()->create();

    $parent = Playlist::factory()->create(['user_id' => $user->id]);
    $child = Playlist::factory()->create([
        'user_id' => $user->id,
        'parent_id' => $parent->id,
    ]);

    $childChannel = Channel::factory()->create([
        'playlist_id' => $child->id,
        'user_id' => $user->id,
    ]);

    $form = ChannelResource::getForm();
    $fieldset = collect($form)->first(function ($component) {
        return $component instanceof \Filament\Forms\Components\Fieldset
            && $component->getLabel() === 'Failover Channels';
    });

    $select = $fieldset->getChildComponents()[0]->getChildComponents()[0];
    $options = (new Invader($select))->options;

    expect($options($childChannel->id, null))->toBe([]);
});

it('rejects child playlist channel as failover server side', function () {
    $user = User::factory()->create();

    $parent = Playlist::factory()->create(['user_id' => $user->id]);

    $source = Channel::factory()->create([
        'playlist_id' => $parent->id,
        'user_id' => $user->id,
    ]);

    $child = Playlist::factory()->create([
        'user_id' => $user->id,
        'parent_id' => $parent->id,
    ]);

    $childChannel = Channel::factory()->create([
        'playlist_id' => $child->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => $source->failovers()->create([
        'channel_failover_id' => $childChannel->id,
        'user_id' => $user->id,
    ]))->toThrow(ValidationException::class);
});

