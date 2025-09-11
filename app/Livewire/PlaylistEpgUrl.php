<?php

namespace App\Livewire;

use App\Services\EpgCacheService;
use Filament\Notifications\Notification as FilamentNotification;
use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class PlaylistEpgUrl extends Component
{
    public Model $record;
    public string $modalId = '';

    public function render()
    {
        return view('livewire.playlist-epg-url');
    }

    public function mount()
    {
        $this->modalId = 'epg-url-modal-' . $this->record->getKey();
    }

    public function clearEpgFileCache()
    {
        $cleared = EpgCacheService::clearPlaylistEpgCacheFile($this->record);
        if ($cleared) {
            FilamentNotification::make()
                ->title('EPG File Cache Cleared')
                ->body('The EPG file cache has been successfully cleared.')
                ->success()
                ->send();
        } else {
            FilamentNotification::make()
                ->title('EPG File Cache Not Found')
                ->body('No EPG cache files found.')
                ->warning()
                ->send();
        }

        // Close the modal
        $this->dispatch('close-modal', id: $this->modalId);
    }
}
