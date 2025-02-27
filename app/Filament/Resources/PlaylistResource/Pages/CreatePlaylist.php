<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylist extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }
}
