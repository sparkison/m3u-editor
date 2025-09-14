<?php

namespace App\Filament\Resources\PlaylistAliases\Pages;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlaylistAlias extends EditRecord
{
    protected static string $resource = PlaylistAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
