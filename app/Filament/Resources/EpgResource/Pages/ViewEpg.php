<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Filament\Resources\EpgResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEpg extends ViewRecord
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
