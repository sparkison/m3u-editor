<?php

namespace App\Filament\Resources\MergedPlaylistResource\Pages;

use App\Filament\Resources\MergedPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMergedPlaylist extends EditRecord
{
    protected static string $resource = MergedPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
