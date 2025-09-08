<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait DisplaysPlaylistMembership
{
    protected static function getPlaylistNames(Model $record, string $sourceKey): Collection
    {
        if (empty($record->{$sourceKey})) {
            return collect([$record->playlist?->name])->filter();
        }

        return $record->newQuery()
            ->where('user_id', $record->user_id)
            ->where($sourceKey, $record->{$sourceKey})
            ->with('playlist')
            ->get()
            ->pluck('playlist.name')
            ->filter();
    }

    protected static function playlistDisplay(Model $record, string $sourceKey): string
    {
        $names = self::getPlaylistNames($record, $sourceKey);
        $first = $names->first() ?? '';
        $count = $names->count() - 1;
        return $count > 0 ? sprintf('%s +%d', $first, $count) : $first;
    }

    protected static function playlistTooltip(Model $record, string $sourceKey): ?string
    {
        $names = self::getPlaylistNames($record, $sourceKey);
        return $names->count() > 1 ? $names->implode(', ') : null;
    }
}
