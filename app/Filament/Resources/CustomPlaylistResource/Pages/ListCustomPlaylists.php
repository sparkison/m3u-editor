<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomPlaylists extends ListRecords
{
    protected static string $resource = CustomPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->successRedirectUrl(fn($record): string => route('custom_playlist.edit', [
                    'customPlaylist' => $record,
                ])),
        ];
    }
}
