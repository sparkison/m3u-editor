<?php

namespace App\Filament\Resources\PlaylistAliases\Pages;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistAliases extends ListRecords
{
    protected static string $resource = PlaylistAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver(),
        ];
    }
}
