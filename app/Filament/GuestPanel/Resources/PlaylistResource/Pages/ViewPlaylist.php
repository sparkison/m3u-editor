<?php

namespace App\Filament\GuestPanel\Resources\PlaylistResource\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        return static::getResource()::getUrl(static::getResourcePageName(), $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }
}
