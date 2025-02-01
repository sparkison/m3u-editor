<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Filament\Resources\EpgResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpg extends EditRecord
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
