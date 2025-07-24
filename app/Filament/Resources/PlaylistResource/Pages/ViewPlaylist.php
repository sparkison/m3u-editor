<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use Filament\Actions;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit Playlist')
                ->icon('heroicon-m-pencil')
                ->color('primary')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('edit'));
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');
        return PlaylistResource::infolist($infolist, $record);
    }
}
