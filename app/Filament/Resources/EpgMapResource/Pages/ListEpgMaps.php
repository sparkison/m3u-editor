<?php

namespace App\Filament\Resources\EpgMapResource\Pages;

use App\Filament\Resources\EpgMapResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListEpgMaps extends ListRecords
{
    protected static string $resource = EpgMapResource::class;

    protected ?string $subheading = 'View the EPG channel mapping jobs and progress here. Head to channels to create a new mapping.';

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');
    }
}
