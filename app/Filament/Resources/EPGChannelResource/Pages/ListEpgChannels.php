<?php

namespace App\Filament\Resources\EpgChannelResource\Pages;

use App\Filament\Resources\EpgChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEpgChannels extends ListRecords
{
    protected static string $resource = EpgChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
