<?php

namespace App\Filament\Resources\EpgChannelResource\Pages;

use App\Filament\Resources\EpgChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpgChannel extends EditRecord
{
    protected static string $resource = EpgChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
