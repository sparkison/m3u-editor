<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\Pages;

use App\Filament\Resources\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncStatus extends ViewRecord
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Fieldset::make('sync_stats')
                    ->label('Sync Stats')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Playlist name'),
                        Infolists\Components\TextEntry::make('sync_stats.time_rounded')
                            ->label('Sync time')
                            ->helperText('Total time to sync playlist (in seconds)'),
                    ]),
            ]);
    }
}
