<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

trait DisplaysPlaylistMembership
{
    protected static function getPlaylistNames(Model $record, string $sourceKey): Collection
    {
        $userId = $record->user_id ?? auth()->id();

        if (empty($record->{$sourceKey})) {
            return collect([$record->playlist?->name])->filter();
        }

        $table = $record->getTable();

        $query = $record->newQuery()
            ->select("{$table}.*")
            ->where("{$table}.user_id", $record->user_id)
            ->where("{$table}.{$sourceKey}", $record->{$sourceKey})
            ->with('playlist');

        if (Schema::hasColumn('playlists', 'parent_id')) {
            $query->join('playlists', "{$table}.playlist_id", '=', 'playlists.id')
                ->orderByRaw('COALESCE(playlists.parent_id, playlists.id), playlists.parent_id IS NOT NULL, playlists.id');
        } else {
            $query->orderBy("{$table}.playlist_id");
        }

        return $query->get()
            ->pluck('playlist.name')
            ->filter();
    }

    protected static function playlistDisplay(Model $record, string $sourceKey): string
    {
        $names = self::getPlaylistNames($record, $sourceKey);
        $first = $names->first() ?? '';
        $count = $names->count() - 1;
        return $count > 0 ? sprintf('%s +%d', $first, $count) : '';
    }

    protected static function playlistTooltip(Model $record, string $sourceKey): ?string
    {
        $names = self::getPlaylistNames($record, $sourceKey);
        return $names->count() > 1 ? $names->implode(', ') : null;
    }
}
