<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\PlaylistResource\Widgets;
use App\Models\Playlist;
use Filament\Actions;
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
            EditAction::make()
                ->label('Edit Playlist')
                ->icon('heroicon-m-pencil')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('edit'));
                }),
            Action::make('Sync Logs')
                ->label('Sync Logs')
                ->color('gray')
                ->icon('heroicon-m-arrows-right-left')
                ->url(
                    fn(): string => PlaylistResource::getUrl(
                        name: 'playlist-sync-statuses.index',
                        parameters: [
                            'parent' => $this->getRecord()->id,
                        ]
                    )
                ),
            //...PlaylistResource::getHeaderActions()
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');
        return PlaylistResource::infolist($infolist);
    }
}
