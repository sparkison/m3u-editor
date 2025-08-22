<?php

namespace App\Filament\Resources\MergedPlaylists\Pages;

use App\Filament\Resources\MergedPlaylists\Pages\EditMergedPlaylist;
use Filament\Actions\CreateAction;
use App\Filament\Resources\MergedPlaylists\MergedPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMergedPlaylists extends ListRecords
{
    protected static string $resource = MergedPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->successRedirectUrl(fn($record): string => EditMergedPlaylist::getUrl(['record' => $record])),

        ];
    }
}
