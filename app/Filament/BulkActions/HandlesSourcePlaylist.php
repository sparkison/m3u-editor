<?php

namespace App\Filament\BulkActions;

use App\Models\CustomPlaylist;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification as FilamentNotification;
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
        $recordSourceIds = $records->pluck($sourceKey)->unique();

        $rows = DB::table($relation)
            ->join('playlists', $relation . '.playlist_id', '=', 'playlists.id')
            ->where('playlists.user_id', auth()->id())
            ->whereIn($sourceKey, $recordSourceIds)
            ->select('playlist_id', 'parent_id', $sourceKey . ' as source_id')
            ->get();

        $playlistIds = $rows->pluck('playlist_id')
            ->merge($rows->pluck('parent_id'))
            ->filter()
            ->unique();

        $playlistNames = Playlist::whereIn('id', $playlistIds)->pluck('name', 'id');

        $groups = [];

        $rows->groupBy('source_id')
            ->each(function ($group, $sourceId) use (&$groups, $playlistNames) {
                $ids        = $group->pluck('playlist_id')->unique();
                $parentMap  = $group->mapWithKeys(fn ($row) => [$row->playlist_id => $row->parent_id]);

                if ($ids->count() <= 1) {
                    return;
                }

                foreach ($ids as $id) {
                    $parentId = $parentMap[$id] ?? null;

                    if ($parentId && $ids->contains($parentId)) {
                        $pairKey = $parentId . '-' . $id;

                        $groups[$pairKey] ??= [
                            'parent_id'      => $parentId,
                            'child_id'       => $id,
                            'playlists'      => collect($playlistNames->only([$parentId, $id])),
                            'source_ids'     => [],
                            'composite_keys' => [],
                        ];

                        $groups[$pairKey]['source_ids'][]     = $sourceId;
                        $groups[$pairKey]['composite_keys'][] = $id . ':' . $sourceId;
                        $groups[$pairKey]['composite_keys'][] = $parentId . ':' . $sourceId;
                    }
                }
            });

        $duplicateGroups = collect($groups);

        // Map composite playlist & source IDs to their parent-child pair
        $sourceToGroup = $duplicateGroups
            ->flatMap(fn ($group, $pairKey) => collect($group['composite_keys'])
                ->unique()
                ->mapWithKeys(fn ($key) => [$key => $pairKey]));

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
     * @param string          $modelClass         Fully qualified model class for querying record details.
     * @param array|null      $sourcePlaylistData Cached metadata returned from {@see getSourcePlaylistData}.
     *                                           Passed by reference so callers can reuse the computed data.
     * @return array                             Array of Filament form components for inclusion in the bulk action.
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

        $fields      = [];
        $selectedIds = $records->pluck('id');

        // Count how many selected records belong to each parent-child pair so we can
        // require a bulk selection unless every record in the group has an override.
        $groupCounts = [];
        foreach ($records as $record) {
            $sourceId  = $record->$sourceKey;
            $composite = $record->playlist_id . ':' . $sourceId;
            if ($sourceToGroup->has($composite)) {
                $pairKey = $sourceToGroup[$composite];
                $groupCounts[$pairKey] = ($groupCounts[$pairKey] ?? 0) + 1;
            }
        }

        foreach ($duplicateGroups as $pairKey => $group) {
            $parentName = $group['playlists'][$group['parent_id']];
            $childName  = $group['playlists'][$group['child_id']];

            $recordCount = $groupCounts[$pairKey] ?? 0;

            $fields[] = Forms\Components\Fieldset::make('These items appear in synced playlists.')
                ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\Select::make("source_playlists.{$pairKey}")
                                ->label('Use items from:')
                                ->options($group['playlists']->toArray())
                                ->placeholder('Choose source playlist')
                                ->searchable()
                                ->required(fn (Get $get) => count($get("source_playlists_items.{$pairKey}") ?? []) < $recordCount)
                                ->columnSpan(2),
                            Actions::make([
                                Action::make("view_affected_{$pairKey}")
                                    ->label('View affected items')
                                    ->modalHeading("Items in {$parentName} ↔ {$childName}")
                                    ->form(function () use ($group, $pairKey, $modelClass, $sourceKey, $selectedIds) {
                                        $instance = new $modelClass();
                                        $table = $instance->getTable();

                                        $select = ['id'];
                                        if (Schema::hasColumn($table, 'title')) {
                                            $select[] = 'title';
                                        }
                                        if (Schema::hasColumn($table, 'name')) {
                                            $select[] = 'name';
                                        }

                                        $records = $modelClass::query()
                                            ->whereIn('id', $selectedIds)
                                            ->whereIn('playlist_id', [$group['parent_id'], $group['child_id']])
                                            ->whereIn($sourceKey, $group['source_ids'])
                                            ->select($select)
                                            ->get();

                                        return [
                                            Forms\Components\Group::make()
                                                ->statePath("source_playlists_items.{$pairKey}")
                                                ->schema(
                                                    $records->map(fn ($record) =>
                                                        Forms\Components\Select::make((string) $record->id)
                                                            ->label($record->title ?? $record->name ?? '')
                                                            ->inlineLabel()
                                                            ->options($group['playlists']->toArray())
                                                            ->placeholder('Use group selection')
                                                            ->searchable()
                                                    )->toArray()
                                                ),
                                        ];
                                    }),
                            ])
                                ->columnSpan(1)
                                ->alignEnd(),
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

            $groupCounts = [];
            foreach ($records as $record) {
                $sourceId  = $record->$sourceKey;
                $composite = $record->playlist_id . ':' . $sourceId;
                if ($sourceToGroup->has($composite)) {
                    $pairKey = $sourceToGroup[$composite];
                    $groupCounts[$pairKey] = ($groupCounts[$pairKey] ?? 0) + 1;
                }
            }

            foreach ($duplicateGroups as $pairKey => $group) {
                $bulk  = $selected[$pairKey] ?? null;
                $items = collect($itemSelected[$pairKey] ?? [])->filter();
                $count = $groupCounts[$pairKey] ?? 0;

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
