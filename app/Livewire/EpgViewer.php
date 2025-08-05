<?php

namespace App\Livewire;

use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class EpgViewer extends Component implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    public ?array $data = [];
    public $record;
    public $editingChannelId = null;

    public function mount($record): void
    {
        $this->record = $record;
    }

    protected function getActions(): array
    {
        return [
            $this->editChannelAction(),
        ];
    }

    public function editChannelAction(): Action
    {
        return EditAction::make('editChannel')
            ->label('Edit Channel')
            ->record(function () {
                if (!$this->editingChannelId) {
                    return null;
                }
                // is_custom true? Why? Shouldn't be a custom channel in most cases...
                $channel = Channel::with([
                    'playlist',
                    'epgChannel',
                    'failovers'
                ])->find($this->editingChannelId);
                return $channel;
            })
            ->form(ChannelResource::getForm(edit: true))
            ->action(function (array $data, $record) {
                if ($record) {
                    $record->update($data);

                    Notification::make()
                        ->success()
                        ->title('Channel updated')
                        ->body('The channel has been successfully updated.')
                        ->send();
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
        $class = class_basename($this->record);
        $route = $class === 'Epg'
            ? route('api.epg.data', ['uuid' => $this->record?->uuid])
            : route('api.epg.playlist.data', ['uuid' => $this->record?->uuid]);

        return view('livewire.epg-viewer', [
            'route' => $route,
        ]);
    }
}
