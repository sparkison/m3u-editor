<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use App\Models\Channel;
use App\Models\ChannelFailover;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditCustomPlaylist extends EditRecord
{
    protected static string $resource = CustomPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Get the data from the form
        $formData = $this->form->getState(); // Use this to get all form data

        $masterChannelId = Arr::get($formData, 'master_channel_id_for_failover_settings');
        $repeaterData = Arr::get($formData, 'custom_playlist_failovers_repeater');

        if ($masterChannelId && !empty($repeaterData)) {
            $masterChannel = Channel::find($masterChannelId);
            if ($masterChannel) {
                // Delete existing failovers for this master channel
                $masterChannel->failovers()->delete();

                // Create new failover records from the repeater data
                foreach ($repeaterData as $index => $failoverItem) {
                    $failoverChannelId = Arr::get($failoverItem, 'channel_failover_id');
                    if ($failoverChannelId) {
                        ChannelFailover::create([
                            'channel_id' => $masterChannel->id,
                            'channel_failover_id' => $failoverChannelId,
                            'sort' => $index + 1, // Assuming sort order is based on repeater index
                        ]);
                    }
                }
            }
        } elseif ($masterChannelId) {
            // Master channel was selected, but repeater is empty, so delete all failovers for it
            $masterChannel = Channel::find($masterChannelId);
            if ($masterChannel) {
                $masterChannel->failovers()->delete();
            }
        }
        // If no master channel was selected, we don't modify any failovers.
        // The repeater was hidden and its data (being dehydrated(false)) wouldn't be submitted anyway,
        // but this logic ensures we only act if a master channel context was active.
    }
}
