<?php

namespace App\Filament\Resources\PostProcessResource\Pages;

use App\Filament\Resources\PostProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePostProcess extends CreateRecord
{
    protected static string $resource = PostProcessResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        return $data;
    }
}
