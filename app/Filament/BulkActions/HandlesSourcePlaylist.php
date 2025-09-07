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
     * @param  Collection  $records  Selected records from the bulk action.
     * @param  string  $relation  Relationship name used to query playlist items (channels, series, etc.).
     * @param  string  $sourceKey  Source identifier column on the related model.
     * @return array{0: Collection, 1: bool, 2: Collection, 3: Collection} Tuple containing
     *                                                                     duplicate groups, whether a source playlist is
     *                                                                     needed, the source IDs of the records, and a
     *                                                                     map of composite playlist/source keys to their
     *                                                                     parent-child group key.
     */
    protected static function getSourcePlaylistData(Collection $records, string $relation, string $sourceKey): array
    {
        $recordPlaylistIds = $records->pluck('playlist_id')->unique();
        $recordSourceIds = $records->pluck($sourceKey)->filter()->unique();

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
                    $query->orWhereIn('id', $parentIds);
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
            ->flatMap(fn ($playlist) => $playlist->$relation->map(fn ($item) => [
                'source_id' => $item->$sourceKey,
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
                        $pairKey = $playlist->parent_id.'-'.$id;

                        $groups[$pairKey] ??= [
                            'parent_id' => $playlist->parent_id,
                            'child_id' => $id,
                            'playlists' => $playlistMap
                                ->only([$playlist->parent_id, $id])
                                ->map->name,
                            'source_ids' => [],
                            'composite_keys' => [],
                        ];

                        $groups[$pairKey]['source_ids'][] = $sourceId;
                        $groups[$pairKey]['composite_keys'][] = $id.':'.$sourceId;
                        $groups[$pairKey]['composite_keys'][] = $playlist->parent_id.':'.$sourceId;
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
            $sourceId = $record->$sourceKey;
            $composite = $record->playlist_id.':'.$sourceId;

            if (! $sourceToGroup->has($composite)) {
                continue;
            }

            $pairKey = $sourceToGroup[$composite];

            $group = $duplicateGroups[$pairKey];
            $group['records'][$record->id] = [
                'id' => $record->id,
                'title' => $record->title ?? $record->name ?? '',
                'source_id' => $sourceId,
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
     * @param  Collection  $records  Records selected in the bulk action.
     * @param  string  $relation  Relationship name used to fetch playlist items.
     * @param  string  $sourceKey  Column containing the source ID on the related model.
     * @param  string  $itemLabel  Human-readable label for the record type (channel, series, etc.).
     * @param  array|null  $sourcePlaylistData  Cached metadata returned from {@see getSourcePlaylistData}.
     *                                          Passed by reference so callers can reuse the computed data.
     * @return array Array of Filament form components for inclusion in the bulk action.
     */
    protected static function buildSourcePlaylistForm(
        Collection $records,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        ?array &$sourcePlaylistData = null,
        ?Collection $grouped = null,
        bool $allowDrilldown = true
    ): array {
        if ($sourcePlaylistData === null) {
            if ($grouped) {
                // Map each record and composite key to its parent group
                $recordGroups = [];
                $compositeGroups = [];
                foreach ($grouped as $groupEntry) {
                    $groupId = $groupEntry['group']->id;
                    foreach ($groupEntry['channels'] as $channel) {
                        $recordGroups[$channel->id] = $groupId;
                        $compositeGroups[$channel->playlist_id.':'.$channel->$sourceKey] = $groupId;
                    }
                }

                // Compute duplicate metadata once over the flattened records
                $sourcePlaylistData = self::getSourcePlaylistData($records, $relation, $sourceKey);
                [$dupGroups, $needsSourcePlaylist, $recordSourceIds, $sourceToGroup] = $sourcePlaylistData;

                // Re-map duplicate groups back to their parent groups with unique keys
                $groupedDuplicates = [];
                foreach ($dupGroups as $pairKey => $group) {
                    foreach ($group['records'] ?? [] as $record) {
                        $gid = $recordGroups[$record['id']] ?? null;
                        if ($gid === null) {
                            continue;
                        }

                        $globalKey = $gid.'|'.$pairKey;
                        $existing = $groupedDuplicates[$globalKey] ?? array_merge($group, ['records' => []]);
                        $existing['records'][$record['id']] = $record;
                        $groupedDuplicates[$globalKey] = $existing;
                    }
                }

                // Map composite keys to the new global pair keys
                $globalSourceToGroup = $sourceToGroup->map(function ($pairKey, $composite) use ($compositeGroups) {
                    $gid = $compositeGroups[$composite] ?? null;

                    return $gid !== null ? $gid.'|'.$pairKey : $pairKey;
                });

                $sourcePlaylistData = [
                    collect($groupedDuplicates),
                    $needsSourcePlaylist,
                    $recordSourceIds,
                    $globalSourceToGroup,
                ];
            } else {
                $sourcePlaylistData = self::getSourcePlaylistData($records, $relation, $sourceKey);
            }
        }

        [$duplicateGroups, $needsSourcePlaylist] = $sourcePlaylistData;

        if (! $needsSourcePlaylist) {
            return [];
        }

        // When grouped records are provided, organise duplicate groups by parent group
        if ($grouped) {
            $byGroup = [];
            foreach ($duplicateGroups as $key => $group) {
                [$groupId, $pairKey] = explode('|', $key, 2);
                $byGroup[$groupId][$pairKey] = $group;
            }

            $fields = [];
            foreach ($grouped as $groupEntry) {
                $groupModel = $groupEntry['group'];
                $groupId = (string) $groupModel->id;
                if (! isset($byGroup[$groupId])) {
                    continue;
                }

                $fields[] = Forms\Components\Fieldset::make('These items appear in synced playlists.')
                    ->schema([
                        Forms\Components\Fieldset::make($groupModel->name)
                            ->schema(collect($byGroup[$groupId])->map(function ($group, $pairKey) use ($groupId, $allowDrilldown) {
                                $globalKey = $groupId.'|'.$pairKey;
                                $parentName = $group['playlists'][$group['parent_id']];
                                $childName = $group['playlists'][$group['child_id']];

                                $schema = [
                                    Forms\Components\Select::make("source_playlists.{$globalKey}")
                                        ->label('Use items from:')
                                        ->options($group['playlists']->toArray())
                                        ->required()
                                        ->searchable(),
                                ];

                                if ($allowDrilldown) {
                                    $schema[] = Actions::make([
                                        Action::make("view_channels_{$globalKey}")
                                            ->label('View channels')
                                            ->modalHeading("Channels in {$parentName} ↔ {$childName}")
                                            ->statePath("source_playlists_items.{$globalKey}")
                                            ->steps(self::buildChannelSteps(collect($group['records'] ?? []), $group['playlists'])),
                                    ]);
                                }

                                return Forms\Components\Fieldset::make("{$parentName} ↔ {$childName}")
                                    ->schema($schema);
                            })->toArray()),
                    ]);
            }

            return $fields;
        }

        // Default behaviour for ungrouped records
        $fields = [];

        foreach ($duplicateGroups as $pairKey => $group) {
            $parentName = $group['playlists'][$group['parent_id']];
            $childName = $group['playlists'][$group['child_id']];

            $schema = [
                Forms\Components\Select::make("source_playlists.{$pairKey}")
                    ->label('Use items from:')
                    ->options($group['playlists']->toArray())
                    ->required()
                    ->searchable(),
            ];

            if ($allowDrilldown) {
                $schema[] = Actions::make([
                    Action::make("view_affected_{$pairKey}")
                        ->label('View affected items')
                        ->modalHeading("Items in {$parentName} ↔ {$childName}")
                        ->statePath("source_playlists_items.{$pairKey}")
                        ->steps(self::buildChannelSteps(collect($group['records'] ?? []), $group['playlists'])),
                ]);
            }

            $fields[] = Forms\Components\Fieldset::make('These items appear in synced playlists.')
                ->schema($schema);
        }

        return $fields;
    }

    /**
     * Build wizard steps to paginate channel overrides.
     */
    protected static function buildChannelSteps(Collection $records, Collection $playlists): array
    {
        $chunks = $records->chunk(10);
        $steps = [];
        foreach ($chunks as $index => $chunk) {
            $steps[] = Forms\Components\Wizard\Step::make('Page '.($index + 1))
                ->schema(
                    $chunk->map(fn ($record) => Forms\Components\Select::make((string) $record['id'])
                        ->label($record['title'])
                        ->options($playlists->toArray())
                        ->placeholder('Use group selection')
                        ->searchable()
                    )->toArray()
                );
        }

        return $steps;
    }

    /**
     * Flatten grouped records into a single collection of models.
     */
    protected static function flattenRecords(Collection $records): Collection
    {
        $first = $records->first();
        if ($first instanceof \Illuminate\Database\Eloquent\Model) {
            return $records;
        }

        return $records->flatMap(fn ($group) => $group['channels']);
    }

    /**
     * Resolve each selected record to the appropriate source playlist entry
     * based on the user's selections.
     *
     * Performs validation to ensure every duplicate parent/child group has a
     * source playlist chosen, and replaces records with their counterpart from
     * the selected source playlist.
     *
     * @param  Collection  $records  Records originally selected in the bulk action.
     * @param  array  $data  Form data submitted by the user.
     * @param  string  $relation  Relationship name used to fetch playlist items.
     * @param  string  $sourceKey  Source identifier column on the related model.
     * @param  string  $modelClass  Fully qualified model class name for the records.
     * @param  array|null  $sourcePlaylistData  Cached metadata from {@see getSourcePlaylistData}.
     *                                          Passed by reference to avoid recomputation.
     * @return Collection Collection of records mapped to their chosen source playlist.
     *
     * @throws ValidationException If any duplicate group lacks a source selection.
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
            $selected = collect($data['source_playlists'] ?? []);
            $itemSelected = collect($data['source_playlists_items'] ?? []);

            foreach ($duplicateGroups as $pairKey => $group) {
                $bulk = $selected[$pairKey] ?? null;
                $items = collect($itemSelected[$pairKey] ?? [])->filter();
                $count = count($group['records'] ?? []);

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
                $sourceId = $record->$sourceKey;
                $composite = $record->playlist_id.':'.$sourceId;

                if ($sourceToGroup->has($composite)) {
                    $pairKey = $sourceToGroup[$composite];
                    $override = $itemSelected[$pairKey][$record->id] ?? null;
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
     * Construct a Filament table action that adds the selected records to a
     * custom playlist, including optional source playlist disambiguation.
     *
     * @param  string  $modelClass  Fully qualified model class for the records.
     * @param  string  $relation  Relationship name used by the custom playlist (channels, series, vods).
     * @param  string  $sourceKey  Column containing the source ID on the related model.
     * @param  string  $itemLabel  Human-readable label for the record type.
     * @param  string  $tagType  Tag type used when assigning categories/groups.
     * @param  string  $categoryLabel  Label displayed for the category select.
     * @return Tables\Actions\Action|Tables\Actions\BulkAction Configured action ready to attach to a Filament table.
     */
    protected static function buildAddToCustomPlaylistAction(
        string $modelClass,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $tagType,
        string $categoryLabel = 'Custom Group',
        ?callable $recordsResolver = null,
        bool $allowDrilldown = true,
        string $actionClass = \Filament\Tables\Actions\BulkAction::class,
        bool $isBulk = true
    ): Tables\Actions\Action|Tables\Actions\BulkAction {
        $sourcePlaylistData = null;

        /** @var Tables\Actions\Action|Tables\Actions\BulkAction $action */
        $action = $actionClass::make('add')
            ->label('Add to Custom Playlist')
            ->form(function ($records) use ($relation, $sourceKey, $itemLabel, $tagType, $categoryLabel, &$sourcePlaylistData, $recordsResolver, $isBulk, $allowDrilldown): array {
                $records = $isBulk ? $records : collect([$records]);
                if ($recordsResolver) {
                    $records = $recordsResolver($records);
                }

                $flatRecords = self::flattenRecords($records);

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
                            : 'Select the '.($categoryLabel === 'Custom Group' ? 'group' : 'category').
                                ' you would like to assign to the selected '.$itemLabel.' to.')
                        ->options(function ($get) use ($tagType) {
                            $customList = CustomPlaylist::find($get('playlist'));

                            return $customList ? $customList->tags()
                                ->where('type', $customList->uuid.$tagType)
                                ->get()
                                ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                ->toArray() : [];
                        })
                        ->searchable(),
                ];

                $grouped = $records->first() instanceof \Illuminate\Database\Eloquent\Model ? null : $records;
                $form = array_merge(
                    $form,
                    self::buildSourcePlaylistForm($flatRecords, $relation, $sourceKey, $itemLabel, $sourcePlaylistData, $grouped, $allowDrilldown)
                );

                return $form;
            })
            ->action(function ($records, array $data) use ($modelClass, $relation, $sourceKey, &$sourcePlaylistData, $recordsResolver, $isBulk, $itemLabel, $allowDrilldown): void {
                $records = $isBulk ? $records : collect([$records]);
                if ($recordsResolver) {
                    $records = $recordsResolver($records);
                }

                $flatRecords = self::flattenRecords($records);

                $grouped = $records->first() instanceof \Illuminate\Database\Eloquent\Model ? null : $records;
                self::buildSourcePlaylistForm($flatRecords, $relation, $sourceKey, $itemLabel, $sourcePlaylistData, $grouped, $allowDrilldown);

                $records = self::mapRecordsToSourcePlaylist($flatRecords, $data, $relation, $sourceKey, $modelClass, $sourcePlaylistData);

                $playlist = CustomPlaylist::findOrFail($data['playlist']);
                $playlist->$relation()->syncWithoutDetaching($records->pluck('id'));
                if ($data['category']) {
                    $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                }
            })
            ->after(function () use ($itemLabel) {
                Notification::make()
                    ->success()
                    ->title(ucfirst($itemLabel).' added to custom playlist')
                    ->body("The selected {$itemLabel} have been added to the chosen custom playlist.")
                    ->send();
            })
            ->requiresConfirmation()
            ->icon('heroicon-o-play')
            ->modalIcon('heroicon-o-play')
            ->modalDescription("Add the selected {$itemLabel} to the chosen custom playlist.")
            ->modalSubmitActionLabel('Add now');

        if ($isBulk && method_exists($action, 'deselectRecordsAfterCompletion')) {
            $action->deselectRecordsAfterCompletion();
        }

        return $action;
    }

    public static function addToCustomPlaylistBulkAction(
        string $modelClass,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $tagType,
        string $categoryLabel = 'Custom Group',
        ?callable $recordsResolver = null,
        bool $allowDrilldown = true
    ): Tables\Actions\BulkAction {
        return self::buildAddToCustomPlaylistAction(
            $modelClass,
            $relation,
            $sourceKey,
            $itemLabel,
            $tagType,
            $categoryLabel,
            $recordsResolver,
            $allowDrilldown,
            \Filament\Tables\Actions\BulkAction::class,
            true
        );
    }

    public static function addToCustomPlaylistAction(
        string $modelClass,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $tagType,
        string $categoryLabel = 'Custom Group',
        ?callable $recordsResolver = null,
        bool $allowDrilldown = true,
        string $actionClass = \Filament\Tables\Actions\Action::class
    ): \Filament\Tables\Actions\Action {
        return self::buildAddToCustomPlaylistAction(
            $modelClass,
            $relation,
            $sourceKey,
            $itemLabel,
            $tagType,
            $categoryLabel,
            $recordsResolver,
            $allowDrilldown,
            $actionClass,
            false
        );
    }
}