<?php

namespace App\Filament\GuestPanel\Resources\PlaylistResource\Pages;

use App\Filament\GuestPanel\Resources\PlaylistResource;
use Filament\Resources\Pages\ListRecords;

class ListPlaylists extends ListRecords
{
    protected static string $resource = PlaylistResource::class;
}
