<?php

namespace App\Livewire;

use App\Filament\Resources\ChannelResource;
use App\Filament\Resources\EpgChannelResource;
use App\Models\Channel;
use App\Models\EpgChannel;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class EpgViewer extends Component implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    public ?array $data = [];
    public $record;
    public $type;
    public $editingChannelId = null;
    protected $cachedRecord = null;

    public function mount($record): void
    {
        $this->record = $record;
        $this->type = class_basename($this->record);
    }

    protected function getActions(): array
    {
        return [
            $this->editChannelAction(),
        ];
    }

    public function editChannelAction(): Action
    {
        // Return "EditAction" to get correct form and action for editing channels
        return EditAction::make('editChannel')
            ->label('Edit Channel')
            ->record(function () {
                // Use cached record if available and matches current editing channel
                if ($this->cachedRecord && $this->cachedRecord->id == $this->editingChannelId) {
                    Log::debug('Using cached record', [
                        'channel_id' => $this->cachedRecord->id,
                        'channel_name' => $this->cachedRecord->name ?? 'unknown'
                    ]);
                    return $this->cachedRecord;
                }
                
                Log::debug('EditAction record function called', [
                    'editingChannelId' => $this->editingChannelId,
                    'type' => $this->type,
                    'cached_record_id' => $this->cachedRecord ? $this->cachedRecord->id : 'none'
                ]);
                
                if (!$this->editingChannelId) {
                    Log::warning('No editingChannelId set');
                    return null;
                }
                
                $channel = $this->type === 'Epg'
                    ? EpgChannel::find($this->editingChannelId)
                    : Channel::with([
                        'epgChannel',
                        'failovers'
                    ])->find($this->editingChannelId);
                
                // Cache the record for subsequent calls
                $this->cachedRecord = $channel;
                
                Log::debug('Found and cached channel', [
                    'channel_id' => $channel?->id,
                    'channel_name' => $channel?->name ?? 'unknown',
                    'query_type' => $this->type
                ]);
                
                return $channel;
            })
            ->form($this->type === 'Epg' ? EpgChannelResource::getForm() : ChannelResource::getForm(edit: true))
            ->action(function (array $data, $record) {
                Log::debug('EditAction action called', [
                    'record_id' => $record?->id,
                    'data_keys' => array_keys($data)
                ]);
                
                if ($record) {
                    $record->update($data);

                    Notification::make()
                        ->success()
                        ->title('Channel updated')
                        ->body('The channel has been successfully updated.')
                        ->send();

                    Log::debug('Channel updated, triggering EPG refresh', [
                        'channel_id' => $record->id,
                        'channel_name' => $record->name ?? 'unknown'
                    ]);

                    // Refresh the EPG data to reflect the changes
                    $this->dispatch('refresh-epg-data');
                }
                
                // Clear cache after action completes
                Log::debug('Clearing cache after action completion');
                $this->cachedRecord = null;
                $this->editingChannelId = null;
            })
            ->slideOver()
            ->modalWidth('4xl');
    }

    public function openChannelEdit($channelId)
    {
        Log::debug('Opening channel edit modal', [
            'channel_id' => $channelId,
            'type' => $this->type,
            'current_editing_id' => $this->editingChannelId,
            'has_cached_record' => $this->cachedRecord ? $this->cachedRecord->id : 'none'
        ]);
        
        // Only clear cache if we're editing a different channel
        if ($this->editingChannelId !== $channelId) {
            Log::debug('Clearing cache - different channel', [
                'old_channel' => $this->editingChannelId,
                'new_channel' => $channelId
            ]);
            $this->cachedRecord = null;
        }
        
        $this->editingChannelId = $channelId;
        $this->mountAction('editChannel');
    }

    public function render()
    {
        $route = $this->type === 'Epg'
            ? route('api.epg.data', ['uuid' => $this->record?->uuid])
            : route('api.epg.playlist.data', ['uuid' => $this->record?->uuid]);

        return view('livewire.epg-viewer', [
            'route' => $route,
        ]);
    }
}
