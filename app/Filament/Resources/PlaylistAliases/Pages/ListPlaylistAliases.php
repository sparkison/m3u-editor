<?php

namespace App\Filament\Resources\PlaylistAliases\Pages;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPlaylistAliases extends ListRecords
{
    protected static string $resource = PlaylistAliasResource::class;

    protected ?string $subheading = 'Create an alias of an existing playlist or custom playlist to use a different Xtream API configuration, while still using the same underlying channel list.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver(),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
