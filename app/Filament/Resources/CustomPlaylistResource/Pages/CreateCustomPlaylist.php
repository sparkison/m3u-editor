<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomPlaylist extends CreateRecord
{
    protected static string $resource = CustomPlaylistResource::class;
}
