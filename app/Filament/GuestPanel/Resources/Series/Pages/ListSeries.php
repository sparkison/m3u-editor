<?php

namespace App\Filament\GuestPanel\Resources\Series\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSeries extends ListRecords
{
    use HasPlaylist;

    protected static string $resource = SeriesResource::class;
    protected static ?string $title = '';

    // @TODO:
    // Need to figure out the getUrl so filters, searches, etc work properly

    // public static function getUrl(
    //     array $parameters = [],
    //     bool $isAbsolute = true,
    //     ?string $panel = null,
    //     $tenant = null
    // ): string {
    //     $parameters['uuid'] = static::getCurrentUuid();
    //     return route(static::getRouteName($panel), $parameters, $isAbsolute);
    // }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        // Filter series by the current playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        return static::getResource()::getEloquentQuery()
            ->where([
                ['enabled', true], // Only show enabled series
                ['playlist_id', $playlist?->id]
            ]);
    }
}
