<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\Pages;

use App\Filament\Resources\PlaylistSyncStatusResource;
use App\Traits\HasParentResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncStatus extends ViewRecord
{
    use HasParentResource;

    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Sync Status')
                    ->description('General sync information')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Playlist name'),
                        Infolists\Components\TextEntry::make('sync_stats.time_rounded')
                            ->label('Sync time')
                            ->helperText('Total time to sync playlist (in seconds)'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Synced at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
