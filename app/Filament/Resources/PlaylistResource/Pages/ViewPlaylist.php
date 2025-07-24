<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use App\Filament\Resources\PlaylistResource\Widgets\ImportProgress;
use Filament\Actions;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit Playlist')
                ->icon('heroicon-m-pencil')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('edit'));
                }),
            ...PlaylistResource::getHeaderActions()
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');
        return PlaylistResource::infolist($infolist, $record);
    }
}
