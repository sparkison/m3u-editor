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
    
    // Use static cache to prevent Livewire from clearing it
    protected static $recordCache = [];
    protected static $maxCacheSize = 20; // Limit cache size to prevent memory issues

    public function mount($record): void
    {
        $this->record = $record;
        $this->type = class_basename($this->record);
    }

    /**
     * Clear old cache entries if cache gets too large
     */
    protected static function maintainCacheSize(): void
    {
        if (count(static::$recordCache) > static::$maxCacheSize) {
            // Remove the oldest entries (first half of cache)
            $halfSize = intval(static::$maxCacheSize / 2);
            static::$recordCache = array_slice(static::$recordCache, $halfSize, null, true);
        }
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
            ->record(fn () => $this->getChannelRecord())
            ->form($this->type === 'Epg' ? EpgChannelResource::getForm() : ChannelResource::getForm(edit: true))
            ->action(function (array $data, $record) {
                if ($record) {
                    $record->update($data);

                    Notification::make()
                        ->success()
                        ->title('Channel updated')
                        ->body('The channel has been successfully updated.')
                        ->send();

                    // Update the static cache with fresh data
                    $cacheKey = "{$this->type}_{$record->id}";
                    static::$recordCache[$cacheKey] = $record->fresh(['epgChannel', 'failovers']);

                    // Refresh the EPG data to reflect the changes
                    $this->dispatch('refresh-epg-data');
                }
                
                $this->editingChannelId = null;
            })
            ->slideOver()
            ->modalWidth('4xl');
    }

    protected function getChannelRecord()
    {
        $cacheKey = "{$this->type}_{$this->editingChannelId}";
        
        // Use static cache if available
        if (isset(static::$recordCache[$cacheKey])) {
            return static::$recordCache[$cacheKey];
        }
        if (!$this->editingChannelId) {
            return null;
        }

        Log::debug('Loading channel record', [
            'editingChannelId' => $this->editingChannelId,
            'type' => $this->type,
            'cache_key' => $cacheKey,
            'cache_size' => count(static::$recordCache)
        ]);
        $channel = $this->type === 'Epg'
            ? EpgChannel::find($this->editingChannelId)
            : Channel::with(['epgChannel', 'failovers'])->find($this->editingChannelId);
        
        // Cache the record in static cache
        if ($channel) {
            static::$recordCache[$cacheKey] = $channel;
            static::maintainCacheSize();
        }
        
        return $channel;
    }

    public function openChannelEdit($channelId)
    {
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
