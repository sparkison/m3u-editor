<?php

namespace App\Filament\Resources\EpgMapResource\Pages;

use App\Filament\Resources\EpgMapResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpgMap extends EditRecord
{
    protected static string $resource = EpgMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
