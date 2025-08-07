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
                    return $this->cachedRecord;
                }
                
                if (!$this->editingChannelId) {
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
                
                return $channel;
            })
            ->form($this->type === 'Epg' ? EpgChannelResource::getForm() : ChannelResource::getForm(edit: true))
            ->action(function (array $data, $record) {
                if ($record) {
                    $record->update($data);

                    Notification::make()
                        ->success()
                        ->title('Channel updated')
                        ->body('The channel has been successfully updated.')
                        ->send();

                    // Refresh the EPG data to reflect the changes
                    $this->dispatch('refresh-epg-data');
                }
                
                // Clear cache after action completes
                $this->cachedRecord = null;
                $this->editingChannelId = null;
            })
            ->slideOver()
            ->modalWidth('4xl');
    }

    public function openChannelEdit($channelId)
    {
        // Only clear cache if we're editing a different channel
        if ($this->editingChannelId !== $channelId) {
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
