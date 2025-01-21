<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Filament\Resources\EpgResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEpgs extends ListRecords
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
