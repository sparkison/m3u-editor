<?php

namespace App\Filament\Resources\PlaylistSyncStatuses\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use App\Traits\HasParentResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncStatus extends ViewRecord
{
    use HasParentResource;

    protected static string $resource = PlaylistSyncStatusResource::class;

    protected static ?string $navigationLabel = 'Log details';
    protected static ?string $title = 'Sync log details';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $infolist
            ->schema([
                Section::make('Sync Status')
                    ->description('General sync information')
                    ->compact()
                    ->collapsible()
                    ->collapsed(true)
                    ->persistCollapsed(true)
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Playlist name'),
                        TextEntry::make('sync_stats.time_rounded')
                            ->label('Sync time')
                            ->helperText('Total time to sync playlist (in seconds)'),
                        TextEntry::make('created_at')
                            ->label('Synced at')
                            ->dateTime(),
                        TextEntry::make('sync_stats.status')
                            ->label('Status')
                            ->default('success')
                            ->badge()
                            ->color(function ($state) {
                                return match ($state) {
                                    'success' => 'success',
                                    'canceled' => 'warning',
                                    default => 'info',
                                };
                            }),
                        TextEntry::make('sync_stats.message')
                            ->label('Message')
                            ->columnSpan(2)
                            ->default('N/A'),
                    ]),
            ]);
    }
}
