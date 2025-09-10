<?php

namespace App\Filament\BulkActions;

use App\Models\CustomPlaylist;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Tables;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Provides helpers for bulk actions that need to resolve the correct
 * source playlist when a record exists in both a parent playlist and one
 * or more of its children.
 *
 * Example usage:
 *
 * ```php
 * class ChannelResource extends Resource
 * {
 *     use \App\Filament\BulkActions\HandlesSourcePlaylist;
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
        $recordSourceIds = $records->pluck($sourceKey)->unique();

        $rows = DB::table($relation)
            ->join('playlists', $relation.'.playlist_id', '=', 'playlists.id')
            ->where('playlists.user_id', auth()->id())
            ->whereIn($sourceKey, $recordSourceIds)
            ->select('playlist_id', 'parent_id', $sourceKey.' as source_id')
            ->get();

        $playlistIds = $rows->pluck('playlist_id')
            ->merge($rows->pluck('parent_id'))
            ->filter()
            ->unique();

        $playlistNames = Playlist::whereIn('id', $playlistIds)->pluck('name', 'id');

        $groups = [];

        $rows->groupBy('source_id')
            ->each(function ($group, $sourceId) use (&$groups, $playlistNames) {
                // Map playlist IDs to their parent IDs for this source
                $parentMap = $group->mapWithKeys(fn ($row) => [$row->playlist_id => $row->parent_id]);

                // Identify parent playlists that also contain the source alongside any children
                $parentMap->unique()->filter()->each(function ($parentId) use ($parentMap, $sourceId, &$groups, $playlistNames) {
                    // Parent must itself contain the source
                    if (! $parentMap->has($parentId)) {
                        return;
                    }

                    // Collect all children of this parent containing the source
                    $childIds = $parentMap
                        ->filter(fn ($pid) => $pid === $parentId)
                        ->keys()
                        ->reject(fn ($id) => $id === $parentId)
                        ->unique();

                    if ($childIds->isEmpty()) {
                        return;
                    }

                    $childKey = $childIds->sort()->join('-');
                    $groupKey = $parentId.'-'.$childKey;

                    $groups[$groupKey] ??= [
                        'parent_id' => $parentId,
                        'child_ids' => $childIds->values()->all(),
                        'playlists' => collect($playlistNames->only(array_merge([$parentId], $childIds->all()))),
                        'source_ids' => [],
                        'composite_keys' => [],
                    ];

                    $groups[$groupKey]['source_ids'][] = $sourceId;

                    foreach (array_merge([$parentId], $childIds->all()) as $id) {
                        $groups[$groupKey]['composite_keys'][] = $id.':'.$sourceId;
                    }
                });
            });

        $duplicateGroups = collect($groups);

        // Map composite playlist & source IDs to their parent-child pair
        $sourceToGroup = $duplicateGroups
            ->flatMap(fn ($group, $groupKey) => collect($group['composite_keys'])
                ->unique()
                ->mapWithKeys(fn ($key) => [$key => $groupKey]));

        $needsSourcePlaylist = $duplicateGroups->isNotEmpty();

        return [$duplicateGroups, $needsSourcePlaylist, $recordSourceIds, $sourceToGroup];
    }

    /**
     * Determine which playlists remain available for a duplicate group,
     * excluding any already used within the chosen custom playlist.
     */
    protected static function availablePlaylistsForGroup(?int $customPlaylistId, array $group, string $relation, string $sourceKey): Collection
    {
        $options = $group['playlists']->collect();

        if (! $customPlaylistId) {
            return $options;
        }

        $playlist = CustomPlaylist::find($customPlaylistId);

        if (! $playlist) {
            return $options;
        }

        $used = $playlist->$relation()
            ->whereIn($sourceKey, $group['source_ids'])
            ->pluck('playlist_id')
            ->unique();

        return $options->except($used);
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
     * @param  string  $modelClass  Fully qualified model class for querying record details.
     * @param  array|null  $sourcePlaylistData  Cached metadata returned from {@see getSourcePlaylistData}.
     *                                          Passed by reference so callers can reuse the computed data.
     * @return array Array of Filament form components for inclusion in the bulk action.
     */
    protected static function buildSourcePlaylistForm(
        Collection $records,
        string $relation,
        string $sourceKey,
        string $itemLabel,
        string $modelClass,
        ?array &$sourcePlaylistData = null
    ): array {
        if ($sourcePlaylistData === null) {
            $sourcePlaylistData = self::getSourcePlaylistData($records, $relation, $sourceKey);
        }

        [$duplicateGroups, $needsSourcePlaylist, , $sourceToGroup] = $sourcePlaylistData;

        if (! $needsSourcePlaylist) {
            return [];
        }

        $labels = $records->mapWithKeys(function ($record) use ($sourceKey) {
            $label = $record->title_custom
                ?? $record->title
                ?? $record->name
                ?? $record->$sourceKey;

            return [$record->$sourceKey => $label];
        })->all();

        $fields = [];

        $sourceLabels = $records
            ->mapWithKeys(fn ($record) => [
                $record->$sourceKey => $record->title ?? $record->name ?? $record->$sourceKey,
            ]);

        foreach ($duplicateGroups as $groupKey => $group) {
            $parentName = $group['playlists'][$group['parent_id']];
            $childNames = $group['playlists']->except($group['parent_id'])->values()->implode(', ');
            $label = $childNames ? "{$parentName} / {$childNames}" : $parentName;

            $fields[] = Forms\Components\Fieldset::make($label)
                ->schema([
                    Forms\Components\Select::make("source_playlists.{$groupKey}")
                        ->label('Which playlist do you want to select from?')
                        ->columnSpanFull()
                        ->options(fn (Get $get) => self::availablePlaylistsForGroup(
                            $get('playlist'),
                            $group,
                            $relation,
                            $sourceKey
                        )->toArray())
                        ->placeholder('Choose playlist')
                        ->required()
                        ->searchable()
                        ->live()
                        ->reactive()
                        ->suffixAction(
                            Action::make("items_{$groupKey}")
                                ->label('View Affected Items')
                                ->icon('heroicon-o-eye')
                                ->color('primary')
                                ->button()
                                ->extraAttributes(['class' => 'whitespace-nowrap'])
                                ->form(function (Get $get) use ($group, $groupKey, $relation, $sourceKey, $labels) {
                                    $existing = $get("source_playlist_items.{$groupKey}") ?? [];
                                    $default = $get("source_playlists.{$groupKey}");

                                    return collect($group['source_ids'])->map(function ($sourceId) use ($group, $existing, $default, $relation, $sourceKey, $labels) {
                                        return Forms\Components\Grid::make(2)
                                            ->schema([
                                                Forms\Components\Select::make("items.{$sourceId}")
                                                    ->label($labels[$sourceId] ?? (string) $sourceId)
                                                    ->options(fn (Get $get) => self::availablePlaylistsForGroup(
                                                        $get('playlist'),
                                                        $group,
                                                        $relation,
                                                        $sourceKey
                                                    )->toArray())
                                                    ->placeholder('Choose playlist')
                                                    ->default($existing[$sourceId] ?? $default)
                                                    ->searchable()
                                                    ->reactive()
                                                    ->inlineLabel()
                                                    ->columnSpan(1),
                                            ]);
                                    })->toArray();
                                })
                                ->action(function (array $data, Set $set) use ($groupKey) {
                                    $set("source_playlist_items.{$groupKey}", $data['items'] ?? []);
                                })
                                ->disabled(fn (Get $get) => blank($get("source_playlists.{$groupKey}")))
                        ),
                    Forms\Components\Hidden::make("source_playlist_items.{$groupKey}")
                        ->default([]),
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
            $groupSelections = collect($data['source_playlists'] ?? []);
            $itemSelections = collect($data['source_playlist_items'] ?? []);

            $sourceAssignments = collect();

            foreach ($duplicateGroups as $groupKey => $group) {
                $available = self::availablePlaylistsForGroup(
                    $data['playlist'] ?? null,
                    $group,
                    $relation,
                    $sourceKey
                );

                if ($available->isEmpty()) {
                    continue;
                }

                $groupChoice = $groupSelections->get($groupKey);
                $items = collect($itemSelections->get($groupKey) ?? []);

                if ($groupChoice && ! $available->has($groupChoice)) {
                    throw ValidationException::withMessages([
                        'source_playlists' => 'Invalid playlist selection.',
                    ]);
                }

                foreach ($items as $playlistId) {
                    if ($playlistId && ! $available->has($playlistId)) {
                        throw ValidationException::withMessages([
                            'source_playlists' => 'Invalid playlist selection.',
                        ]);
                    }
                }

                if (! $groupChoice && $items->filter()->count() !== count($group['source_ids'])) {
                    throw ValidationException::withMessages([
                        'source_playlists' => 'Please select a playlist for each item or choose one at the group level.',
                    ]);
                }

                foreach ($group['source_ids'] as $sourceId) {
                    $chosen = $items[$sourceId] ?? $groupChoice;

                    if ($sourceAssignments->has($sourceId)) {
                        $existing = $sourceAssignments[$sourceId];

                        if ($chosen && $existing && $chosen !== $existing) {
                            throw ValidationException::withMessages([
                                'source_playlists' => 'Conflicting playlist selections were provided for the same item.',
                            ]);
                        }

                        if ($chosen) {
                            $sourceAssignments[$sourceId] = $chosen;
                        }
                    } else {
                        $sourceAssignments[$sourceId] = $chosen;
                    }
                }
            }

            $playlistIds = $sourceAssignments->values()->filter()->unique();

            $sourceMaps = $modelClass::query()
                ->whereIn('playlist_id', $playlistIds)
                ->whereIn($sourceKey, $recordSourceIds)
                ->select('id', 'playlist_id', $sourceKey)
                ->get()
                ->groupBy('playlist_id')
                ->map->keyBy($sourceKey);

            $records = $records->map(function ($record) use ($sourceAssignments, $sourceMaps, $sourceToGroup, $sourceKey) {
                $sourceId = $record->$sourceKey;
                $composite = $record->playlist_id.':'.$sourceId;

                if (! $sourceToGroup->has($composite)) {
                    return $record;
                }

                $playlistId = $sourceAssignments[$sourceId] ?? null;

                return $playlistId && isset($sourceMaps[$playlistId][$sourceId])
                    ? $sourceMaps[$playlistId][$sourceId]
                    : $record;
            });
        }

        return $records;
    }

    /**
     * Construct a Filament bulk action that adds the selected records to a
     * custom playlist, including optional source playlist disambiguation.
     *
     * @param  string  $modelClass  Fully qualified model class for the records.
     * @param  string  $relation  Relationship name used by the custom playlist (channels, series, vods).
     * @param  string  $sourceKey  Column containing the source ID on the related model.
     * @param  string  $itemLabel  Human-readable label for the record type.
     * @param  string  $tagType  Tag type used when assigning categories/groups.
     * @param  string  $categoryLabel  Label displayed for the category select.
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

        $modelClassName = $modelClass;

        return Tables\Actions\BulkAction::make('add')
            ->label('Add to Custom Playlist')
            ->form(function (Collection $records) use (
                $relation,
                $sourceKey,
                $itemLabel,
                $tagType,
                $categoryLabel,
                &$sourcePlaylistData,
                $modelClassName
            ): array {
                $form = [
                    Forms\Components\Select::make('playlist')
                        ->required()
                        ->live()
                        ->label('Custom Playlist')
                        ->helperText("Select the custom playlist you would like to add the selected {$itemLabel} to.")
                        ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->afterStateUpdated(function (Set $set, $state) {
                            $set('source_playlists', []);
                            $set('source_playlist_items', []);

                            if ($state) {
                                $set('category', null);
                            }
                        })
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

                $form = array_merge(
                    $form,
                    self::buildSourcePlaylistForm(
                        $records,
                        $relation,
                        $sourceKey,
                        $itemLabel,
                        $modelClassName,
                        $sourcePlaylistData
                    )
                );

                return $form;
            })
            ->action(function (Collection $records, array $data) use (
                $modelClassName,
                $relation,
                $sourceKey,
                &$sourcePlaylistData
            ): void {
                $records = self::mapRecordsToSourcePlaylist(
                    $records,
                    $data,
                    $relation,
                    $sourceKey,
                    $modelClassName,
                    $sourcePlaylistData
                );

                $playlist = CustomPlaylist::findOrFail($data['playlist']);
                $playlist->$relation()->syncWithoutDetaching($records->pluck('id'));
                if ($data['category']) {
                    $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                }
            })
            ->after(function () use ($itemLabel) {
                FilamentNotification::make()
                    ->success()
                    ->title(ucfirst($itemLabel).' added to custom playlist')
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
