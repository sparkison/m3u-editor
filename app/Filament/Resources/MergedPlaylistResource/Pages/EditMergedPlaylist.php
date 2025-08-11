<?php

namespace App\Filament\Resources\MergedPlaylistResource\Pages;

use App\Filament\Resources\MergedPlaylistResource;
use App\Services\EpgCacheService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMergedPlaylist extends EditRecord
{
    protected static string $resource = MergedPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function clearEpgFileCache()
    {
        $cleared = EpgCacheService::clearPlaylistEpgCacheFile($this->record);
        if ($cleared) {
            Notification::make()
                ->title('EPG File Cache Cleared')
                ->body('The EPG file cache has been successfully cleared.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('EPG File Cache Not Found')
                ->body('No EPG cache files found.')
                ->warning()
                ->send();
        }
    }
}
