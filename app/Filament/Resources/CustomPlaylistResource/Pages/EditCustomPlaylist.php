<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomPlaylist extends EditRecord
{
    protected static string $resource = CustomPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
