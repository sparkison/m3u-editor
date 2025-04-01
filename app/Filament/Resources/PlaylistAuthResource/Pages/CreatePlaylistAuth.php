<?php

namespace App\Filament\Resources\PlaylistAuthResource\Pages;

use App\Filament\Resources\PlaylistAuthResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylistAuth extends CreateRecord
{
    protected static string $resource = PlaylistAuthResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        return $data;
    }
}
