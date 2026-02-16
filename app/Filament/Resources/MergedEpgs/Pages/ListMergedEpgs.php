<?php

namespace App\Filament\Resources\MergedEpgs\Pages;

use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMergedEpgs extends ListRecords
{
    protected static string $resource = MergedEpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->successRedirectUrl(fn ($record): string => EditMergedEpg::getUrl(['record' => $record])),
        ];
    }
}
