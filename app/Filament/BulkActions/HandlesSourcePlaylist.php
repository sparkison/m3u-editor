<?php

namespace App\Filament\BulkActions;

use App\Models\CustomPlaylist;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Provides helpers for bulk actions that need to resolve the correct
 * source playlist when a record exists in both a parent playlist and one
 * or more of its children.
 *
 * Example usage:
 *
 * ```php
 * use App\Filament\BulkActions\HandlesSourcePlaylist;
 *
 * class ChannelResource extends Resource
 * {
 *     use HandlesSourcePlaylist;
 *
 *     public static function getTableBulkActions(): array
 *     {
 *         return [
 *             self::addToCustomPlaylistBulkAction(
 *                 \App\Models\Channel::class,
 *                 'channels',
 *                 'source_id',
 *                 'channel',
 *                 'channel'
 *             ),
 *         ];
 *     }
 * }
 * ```
 */
trait HandlesSourcePlaylist
{
    /**
     * Build duplicate playlist metadata for the given records.
     *
     * @param Collection $records   Selected records from the bulk action.
     * @param string     $relation  Relationship name used to query playlist items (channels, series, etc.).
     * @param string     $sourceKey Source identifier column on the related model.
     * @return array{0: Collection, 1: bool, 2: Collection, 3: Collection} Tuple containing
     *                                             duplicate groups, whether a source playlist is
     *                                             needed, the source IDs of the records, and a
     *                                             map of composite playlist/source keys to their
     *                                             parent-child group key.
    */
    protected static function getSourcePlaylistData(Collection $records, string $relation, string $sourceKey): array
    {
        $recordPlaylistIds = $records->pluck('playlist_id')->unique();
        $recordSourceIds   = $records->pluck($sourceKey)->unique();

        $parentIds = Playlist::whereIn('id', $recordPlaylistIds)
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->all();

        $playlists = Playlist::where('user_id', auth()->id())
            ->select('id', 'parent_id', 'name')
            ->where(function ($query) use ($recordPlaylistIds, $parentIds) {
                $query->whereIn('id', $recordPlaylistIds)
                    ->orWhereIn('parent_id', $recordPlaylistIds);

                if (! empty($parentIds)) {
                    $query->orWhereIn('id', $parentIds)
                        ->orWhereIn('parent_id', $parentIds);
                }
            })
            ->whereHas($relation, fn ($q) => $q->whereIn($sourceKey, $recordSourceIds))
            ->with([
                $relation => fn ($q) => $q
                    ->select('id', 'playlist_id', $sourceKey)
                    ->whereIn($sourceKey, $recordSourceIds),
            ])
            ->get();

        $playlistMap = $playlists->keyBy('id');

        $groups = [];

        $playlists
            ->flatMap(fn ($playlist) => ($playlist->$relation ?? collect())->map(fn ($item) => [
                'source_id'   => $item->$sourceKey,
                'playlist_id' => $playlist->id,
            ]))
            ->groupBy('source_id')
            ->each(function ($group, $sourceId) use (&$groups, $playlistMap) {
                $ids = $group->pluck('playlist_id')->unique();

                if ($ids->count() <= 1) {
                    return;
                }

                foreach ($ids as $id) {
                    $playlist = $playlistMap[$id];

                    if ($playlist->parent_id && $ids->contains($playlist->parent_id)) {
                        $pairKey = $playlist->parent_id . '-' . $id;

                        $groups[$pairKey] ??= [
                            'parent_id'     => $playlist->parent_id,
                            'child_id'      => $id,
                            'playlists'     => $playlistMap
                                ->only([$playlist->parent_id, $id])
                                ->map->name,
                            'source_ids'    => [],
                            'composite_keys'=> [],
                        ];

                        $groups[$pairKey]['source_ids'][]     = $sourceId;
                        $groups[$pairKey]['composite_keys'][] = $id . ':' . $sourceId;
                        $groups[$pairKey]['composite_keys'][] = $playlist->parent_id . ':' . $sourceId;
                    }
                }
            });

        $duplicateGroups = collect($groups);

        // Map composite playlist & source IDs to their parent-child pair
        $sourceToGroup = $duplicateGroups
            ->flatMap(fn ($group, $pairKey) => collect($group['composite_keys'])
                ->unique()
                ->mapWithKeys(fn ($key) => [$key => $pairKey]));

        // Store the selected record details under their respective group
        foreach ($records as $record) {
            $sourceId  = $record->$sourceKey;
            $composite = $record->playlist_id . ':' . $sourceId;

            if (! $sourceToGroup->has($composite)) {
                continue;
            }

            $pairKey = $sourceToGroup[$composite];

            $group = $duplicateGroups[$pairKey];
            $group['records'][$record->id] = [
                'id'          => $record->id,
                'title'       => $record->title ?? $record->name ?? '',
                'source_id'   => $sourceId,
                'playlist_id' => $record->playlist_id,
            ];
            $duplicateGroups[$pairKey] = $group;
        }

        $needsSourcePlaylist = $duplicateGroups->isNotEmpty();

        return [$duplicateGroups, $needsSourcePlaylist, $recordSourceIds, $sourceToGroup];
    }

    /**
     * Build form fields allowing users to choose the source playlist for
     * duplicate parent/child groups and optionally override individual
     * records within those groups.
     *
     * @param Collection      $records            Records selected in the bulk action.
     * @param string          $relation           Relationship name used to fetch playlist items.
     * @param string          $sourceKey          Column containing the source ID on the related model.
     * @param string          $itemLabel          Human-readable label for the record type (channel, series, etc.).
     * @param array|null      $sourcePlaylistData Cached metadata returned from {@see getSourcePlaylistData}.
     *                                           Passed by reference so callers can reuse the computed data.
     * @return array                             Array of Filament form components for inclusion in the bulk action.
     */
    protected static function buildSourcePlaylistForm(
        Collection $records,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        ?array &$sourcePlaylistData = null
    ): array {
        if ($sourcePlaylistData === null) {
            $sourcePlaylistData = self::getSourcePlaylistData($records, $relation, $sourceKey);
        }

        [$duplicateGroups, $needsSourcePlaylist] = $sourcePlaylistData;

        if (! $needsSourcePlaylist) {
            return [];
        }

        $fields = [];

        foreach ($duplicateGroups as $pairKey => $group) {
            $parentName = $group['playlists'][$group['parent_id']];
            $childName  = $group['playlists'][$group['child_id']];

            $fields[] = Forms\Components\Fieldset::make('These items appear in synced playlists.')
                ->schema([
                    Forms\Components\Select::make("source_playlists.{$pairKey}")
                        ->label('Use items from:')
                        ->options($group['playlists']->toArray())
                        ->required()
                        ->searchable(),
                    Actions::make([
                        Action::make("view_affected_{$pairKey}")
                            ->label('View affected items')
                            ->modalHeading("Items in {$parentName} ↔ {$childName}")
                            ->statePath("source_playlists_items.{$pairKey}")
                            ->form(
                                collect($group['records'] ?? [])->map(fn ($record) =>
                                    Forms\Components\Select::make((string) $record['id'])
                                        ->label($record['title'])
                                        ->options($group['playlists']->toArray())
                                        ->placeholder('Use group selection')
                                        ->searchable()
                                )->toArray()
                            ),
                    ]),
                ]);
        }

        return $fields;
    }

    /**
     * Resolve each selected record to the appropriate source playlist entry
     * based on the user's selections.
     *
     * Performs validation to ensure every duplicate parent/child group has a
     * source playlist chosen, and replaces records with their counterpart from
     * the selected source playlist.
     *
     * @param Collection $records           Records originally selected in the bulk action.
     * @param array      $data              Form data submitted by the user.
     * @param string     $relation          Relationship name used to fetch playlist items.
     * @param string     $sourceKey         Source identifier column on the related model.
     * @param string     $modelClass        Fully qualified model class name for the records.
     * @param array|null $sourcePlaylistData Cached metadata from {@see getSourcePlaylistData}.
     *                                       Passed by reference to avoid recomputation.
     * @return Collection                    Collection of records mapped to their chosen source playlist.
     * @throws ValidationException           If any duplicate group lacks a source selection.
     */
    protected static function mapRecordsToSourcePlaylist(
        Collection $records,
        array $data,
        string $relation,
        string $sourceKey,
        string $modelClass,
        ?array $sourcePlaylistData = null
    ): Collection {
        if ($sourcePlaylistData === null) {
            $sourcePlaylistData = self::getSourcePlaylistData($records, $relation, $sourceKey);
        }

        [$duplicateGroups, $needsSourcePlaylist, $recordSourceIds, $sourceToGroup] = $sourcePlaylistData;

        if ($needsSourcePlaylist) {
            $selected     = collect($data['source_playlists'] ?? []);
            $itemSelected = collect($data['source_playlists_items'] ?? []);

            foreach ($duplicateGroups as $pairKey => $group) {
                $bulk   = $selected[$pairKey] ?? null;
                $items  = collect($itemSelected[$pairKey] ?? [])->filter();
                $count  = count($group['records'] ?? []);

                if (! $bulk && $items->count() !== $count) {
                    throw ValidationException::withMessages([
                        'source_playlists' => 'Please select a source playlist for each duplicated group.',
                    ]);
                }
            }

            $playlistIds = $selected->filter()->values();
            $playlistIds = $playlistIds->merge(
                $itemSelected->flatMap(fn ($items) => collect($items)->filter()->values())
            )->unique();

            $sourceMaps = $modelClass::query()
                ->whereIn('playlist_id', $playlistIds)
                ->whereIn($sourceKey, $recordSourceIds)
                ->select('id', 'playlist_id', $sourceKey)
                ->get()
                ->groupBy('playlist_id')
                ->map->keyBy($sourceKey);

            $records = $records->map(function ($record) use ($selected, $itemSelected, $sourceMaps, $sourceToGroup, $sourceKey) {
                $sourceId  = $record->$sourceKey;
                $composite = $record->playlist_id . ':' . $sourceId;

                if ($sourceToGroup->has($composite)) {
                    $pairKey    = $sourceToGroup[$composite];
                    $override   = $itemSelected[$pairKey][$record->id] ?? null;
                    $playlistId = $override ?: ($selected[$pairKey] ?? null);

                    return $playlistId && isset($sourceMaps[$playlistId][$sourceId])
                        ? $sourceMaps[$playlistId][$sourceId]
                        : $record;
                }

                return $record;
            });
        }

        return $records;
    }

    /**
     * Construct a Filament bulk action that adds the selected records to a
     * custom playlist, including optional source playlist disambiguation.
     *
     * @param string $modelClass    Fully qualified model class for the records.
     * @param string $relation      Relationship name used by the custom playlist (channels, series, vods).
     * @param string $sourceKey     Column containing the source ID on the related model.
     * @param string $itemLabel     Human-readable label for the record type.
     * @param string $tagType       Tag type used when assigning categories/groups.
     * @param string $categoryLabel Label displayed for the category select.
     * @return Tables\Actions\BulkAction Configured bulk action ready to attach to a Filament table.
     */
    protected static function buildAddToCustomPlaylistAction(
        string $modelClass,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $tagType,
        string $categoryLabel = 'Custom Group'
    ): Tables\Actions\BulkAction {
        $sourcePlaylistData = null;

        return Tables\Actions\BulkAction::make('add')
            ->label('Add to Custom Playlist')
            ->form(function (Collection $records) use ($relation, $sourceKey, $itemLabel, $tagType, $categoryLabel, &$sourcePlaylistData): array {
                $form = [
                    Forms\Components\Select::make('playlist')
                        ->required()
                        ->live()
                        ->label('Custom Playlist')
                        ->helperText("Select the custom playlist you would like to add the selected {$itemLabel} to.")
                        ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->afterStateUpdated(fn (Set $set, $state) => $state ? $set('category', null) : null)
                        ->searchable(),
                    Forms\Components\Select::make('category')
                        ->label($categoryLabel)
                        ->disabled(fn (Get $get) => ! $get('playlist'))
                        ->helperText(fn (Get $get) => ! $get('playlist')
                            ? 'Select a custom playlist first.'
                            : 'Select the ' . ($categoryLabel === 'Custom Group' ? 'group' : 'category') .
                                ' you would like to assign to the selected ' . $itemLabel . ' to.')
                        ->options(function ($get) use ($tagType) {
                            $customList = CustomPlaylist::find($get('playlist'));
                            return $customList ? $customList->tags()
                                ->where('type', $customList->uuid . $tagType)
                                ->get()
                                ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                ->toArray() : [];
                        })
                        ->searchable(),
                ];

                $form = array_merge(
                    $form,
                    self::buildSourcePlaylistForm($records, $relation, $sourceKey, $itemLabel, $sourcePlaylistData)
                );

                return $form;
            })
            ->action(function (Collection $records, array $data) use ($modelClass, $relation, $sourceKey, &$sourcePlaylistData): void {
                $records = self::mapRecordsToSourcePlaylist($records, $data, $relation, $sourceKey, $modelClass, $sourcePlaylistData);

                $playlist = CustomPlaylist::findOrFail($data['playlist']);
                $playlist->$relation()->syncWithoutDetaching($records->pluck('id'));
                if ($data['category']) {
                    $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                }
            })
            ->after(function () use ($itemLabel) {
                Notification::make()
                    ->success()
                    ->title(ucfirst($itemLabel) . ' added to custom playlist')
                    ->body("The selected {$itemLabel} have been added to the chosen custom playlist.")
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->icon('heroicon-o-play')
            ->modalIcon('heroicon-o-play')
            ->modalDescription("Add the selected {$itemLabel} to the chosen custom playlist.")
            ->modalSubmitActionLabel('Add now');
    }

    public static function addToCustomPlaylistBulkAction(
        string $modelClass,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $tagType,
        string $categoryLabel = 'Custom Group'
    ): Tables\Actions\BulkAction {
        return self::buildAddToCustomPlaylistAction($modelClass, $relation, $sourceKey, $itemLabel, $tagType, $categoryLabel);
    }
}
