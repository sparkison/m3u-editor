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
                if (!$this->editingChannelId) {
                    return null;
                }
                $channel = $this->type === 'Epg'
                    ? EpgChannel::find($this->editingChannelId)
                    : Channel::with([
                        'epgChannel',
                        'failovers'
                    ])->find($this->editingChannelId);
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

                    // Log the refresh for debugging
                    Log::debug('Channel updated, triggering EPG refresh', [
                        'channel_id' => $record->id,
                        'channel_name' => $record->name
                    ]);

                    // Refresh the EPG data to reflect the changes
                    $this->dispatch('refresh-epg-data');
                }
            })
            ->slideOver()
            ->modalWidth('4xl');
    }

    public function openChannelEdit($channelId)
    {
        // Ensure we have a numeric ID
        if (is_array($channelId) || is_object($channelId)) {
            Log::error('channelId is not a scalar value:', ['channelId' => $channelId]);
            return;
        }

        $this->editingChannelId = (int) $channelId;
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
