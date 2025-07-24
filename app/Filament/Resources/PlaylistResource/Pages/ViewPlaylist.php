<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Infolists\Components\EpgViewer;
use App\Filament\Resources\PlaylistResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ...
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');
        return $infolist
            ->schema([
                Section::make('Playlist Information')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn($state) => $state?->getColor()),
                        TextEntry::make('synced')
                            ->label('Last Synced')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('enabled_channels_count')
                            ->label('Enabled Channels')
                            ->default($record->enabled_channels_count),
                    ])
                    ->columns(2),

                Section::make('Guide')
                    ->schema([
                        EpgViewer::make(),
                    ])
                    ->collapsible(false),
            ]);
    }
}
