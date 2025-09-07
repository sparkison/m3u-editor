<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\PlaylistSyncChange;
use App\Models\Season;
use App\Models\Series;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Synchronize child playlists with their parent.
 *
 * Implements ShouldBeUnique so only one job per playlist may be queued or
 * running at a time, preventing concurrent syncs.
 */
class SyncPlaylistChildren implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Release the unique lock after an hour so a new sync can
     * run if a previous job fails or never completes.
     */
    public $uniqueFor = 3600;

    /**
     * Map of child channel source IDs to their database IDs.
     *
     * @var array<string, int>
     */
    private array $childChannelMap = [];

    public function __construct(public Playlist $playlist)
    {
        //
    }

    public function uniqueId(): string
    {
        return (string) $this->playlist->id;
    }

    /**
     * Record pending changes and queue a unique sync job.
     *
     * @param  array<string, array<int, string>>  $changes
     */
    public static function debounce(Playlist $playlist, array $changes): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($changes as $type => $ids) {
            $ids = array_values(array_unique($ids));
            $payload = json_encode($ids);
            $escaped = str_replace("'", "''", $payload);
            $now = now();

            if ($driver === 'pgsql') {
                DB::table('playlist_sync_changes')->upsert(
                    [[
                        'playlist_id' => $playlist->id,
                        'change_type' => $type,
                        'item_ids' => DB::raw("'{$escaped}'::jsonb"),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['playlist_id', 'change_type'],
                    [
                        'item_ids' => DB::raw("(select jsonb_agg(distinct value) from jsonb_array_elements(coalesce(playlist_sync_changes.item_ids, '[]'::jsonb) || excluded.item_ids) as t(value))"),
                        'updated_at' => $now,
                    ]
                );
            } elseif ($driver === 'sqlite') {
                DB::table('playlist_sync_changes')->upsert(
                    [[
                        'playlist_id' => $playlist->id,
                        'change_type' => $type,
                        'item_ids' => $payload,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['playlist_id', 'change_type'],
                    [
                        'item_ids' => DB::raw("(select json_group_array(value) from (select distinct value from (select value from json_each(coalesce(playlist_sync_changes.item_ids, json('[]'))) union all select value from json_each(json('{$escaped}')))))"),
                        'updated_at' => $now,
                    ]
                );
            } else {
                DB::table('playlist_sync_changes')->upsert(
                    [[
                        'playlist_id' => $playlist->id,
                        'change_type' => $type,
                        'item_ids' => $payload,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['playlist_id', 'change_type'],
                    ['item_ids', 'updated_at']
                );
            }
        }

        self::dispatch($playlist);
    }

    public function handle(): void
    {
        $parent = $this->playlist->fresh();

        if (! $parent) {
            Log::warning("SyncPlaylistChildren: Parent playlist {$this->playlist->id} not found, aborting child sync.");

            return;
        }

        while (true) {
            $changes = [];
            $processed = 0;

            PlaylistSyncChange::where('playlist_id', $parent->id)
                ->orderBy('id')
                ->chunkById(100, function ($records) use (&$changes, &$processed) {
                    $processed += $records->count();

                    foreach ($records as $record) {
                        $changes[$record->change_type] = $record->item_ids;
                    }

                    PlaylistSyncChange::whereIn('id', $records->pluck('id'))
                        ->delete();
                });

            if ($processed === 0) {
                sleep(1);

                if (! PlaylistSyncChange::where('playlist_id', $parent->id)->exists()) {
                    break;
                }

                continue;
            }

            $parent->children()->chunkById(100, function ($children) use ($parent, $changes) {
                foreach ($children as $child) {
                    $start = now();
                    $child->update([
                        'status' => Status::Processing,
                        'processing' => true,
                        'progress' => 0,
                    ]);

                    DB::beginTransaction();
                    $copiedFile = null;
                    try {
                        $this->childChannelMap = $child->channels()->pluck('id', 'source_id')->all();
                        $this->syncDelta($parent, $child, $changes);

                        DB::commit();

                        $child->update([
                            'status' => Status::Completed,
                            'synced' => now(),
                            'sync_time' => $start->diffInSeconds(now()),
                            'processing' => false,
                            'progress' => 100,
                        ]);
                        event(new SyncCompleted($child));
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        if ($copiedFile && Storage::disk('local')->exists($copiedFile)) {
                            Storage::disk('local')->delete($copiedFile);
                        }
                        $child->update([
                            'status' => Status::Failed,
                            'processing' => false,
                        ]);
                        event(new SyncCompleted($child));
                        throw $e;
                    }
                }
            });
        }

        (new UniqueLock(Cache::driver()))->release($this);

        // Allow a brief window for any late-arriving changes to be written
        sleep(1);

        if (PlaylistSyncChange::where('playlist_id', $parent->id)->exists()) {
            self::dispatch($parent);
        }
    }

    private function findChildChannelId(Playlist $child, Channel $parentChannel): ?int
    {
        $source = $parentChannel->source_id ?? 'ch-'.$parentChannel->id;

        if (isset($this->childChannelMap[$source])) {
            return $this->childChannelMap[$source];
        }

        $id = $child->channels()->where('source_id', $source)->value('id');
        if ($id) {
            $this->childChannelMap[$source] = $id;
        }

        return $id;
    }

    /**
     * @param  array<int, array{channel_id:int, attributes:array<string, mixed>, failover_playlist_id:?int, failover_source_id:?string}>  $pendingFailovers
     */
    private function syncGroups(Playlist $parent, Playlist $child, array &$pendingFailovers): void
    {
        $parentGroupNames = [];
        $parent->groups()->chunkById(100, function ($groups) use ($child, &$parentGroupNames, &$pendingFailovers) {
            $groupRows = [];
            $groupKeys = [];
            foreach ($groups as $group) {
                $key = $group->name_internal
                    ?? (Str::slug($group->name) ?: 'grp-' . $group->id);

                $groupKeys[$group->id] = $key;
                $parentGroupNames[] = $key;
                $groupRows[] = $group->only(['name', 'sort_order', 'user_id', 'is_custom']) + [
                    'playlist_id' => $child->id,
                    'name_internal' => $key,
                ];
            }
            $child->groups()->upsert($groupRows, ['playlist_id', 'name_internal']);
            unset($groupRows);

            $childGroups = $child->groups()->whereIn('name_internal', array_values($groupKeys))->get()->keyBy('name_internal');
            foreach ($groups as $group) {
                $key = $groupKeys[$group->id];
                $childGroup = $childGroups->get($key);
                if (! $childGroup) {
                    Log::info("SyncPlaylistChildren: Child group not found for key '{$key}' on playlist {$child->id}");

                    continue;
                }

                $childGroupId = $childGroup->id;
                $channelSources = [];
                $failovers = [];
                $group->channels()->with('failovers.channelFailover')->chunkById(100, function ($channels) use ($child, $childGroupId, &$channelSources, &$failovers) {
                    $channelRows = [];
                    foreach ($channels as $channel) {
                        $source = $channel->source_id ?? 'ch-'.$channel->id;
                        $channelSources[] = $source;
                        $channelRows[] = $channel->replicate(except: ['id', 'group_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                            'playlist_id' => $child->id,
                            'group_id' => $childGroupId,
                            'source_id' => $source,
                        ];
                        $failovers[$source] = $channel->failovers;
                    }
                    $child->channels()->upsert($channelRows, ['playlist_id', 'source_id']);
                    unset($channelRows);
                });

                $child->channels()->where('group_id', $childGroupId)->where(function ($q) use ($channelSources) {
                    $q->whereNotIn('source_id', $channelSources)
                        ->orWhereNull('source_id');
                })->delete();
                $childChannels = $child->channels()->where('group_id', $childGroupId)->whereIn('source_id', $channelSources)->get()->keyBy('source_id');
                foreach ($failovers as $source => $items) {
                    $childChannel = $childChannels->get($source);
                    if (! $childChannel) {
                        Log::info("SyncPlaylistChildren: Child channel not found for source '{$source}' on playlist {$child->id}");

                        continue;
                    }

                    $childChannel->failovers()->delete();
                    foreach ($items as $failover) {
                        $pendingFailovers[] = [
                            'channel_id' => $childChannel->id,
                            'attributes' => Arr::except($failover->getAttributes(), ['id', 'channel_id', 'created_at', 'updated_at']),
                            'failover_playlist_id' => $failover->channelFailover?->playlist_id,
                            'failover_source_id' => $failover->channelFailover?->source_id,
                        ];
                    }
                }
                unset($channelSources, $failovers);
            }
        });
        $child->groups()->whereNotIn('name_internal', $parentGroupNames)->delete();
    }

    private function syncCategories(Playlist $parent, Playlist $child): void
    {
        $parentCategoryIds = [];
        $parent->categories()->chunkById(100, function ($categories) use ($child, &$parentCategoryIds) {
            $categoryRows = [];
            foreach ($categories as $category) {
                $categorySource = $category->source_category_id ?? 'cat-'.$category->id;
                $parentCategoryIds[] = $categorySource;
                $categoryRows[] = $category->replicate(except: ['id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                    'playlist_id' => $child->id,
                    'source_category_id' => $categorySource,
                ];
            }
            $child->categories()->upsert($categoryRows, Category::SOURCE_INDEX);
            unset($categoryRows);

            $sources = $categories->map(fn ($c) => $c->source_category_id ?? 'cat-'.$c->id);
            $childCategories = $child->categories()->whereIn('source_category_id', $sources)->get()->keyBy('source_category_id');
            foreach ($categories as $category) {
                $catSource = $category->source_category_id ?? 'cat-'.$category->id;
                $childCategoryId = $childCategories[$catSource]->id;
                $category->series()->chunkById(100, function ($seriesChunk) use ($child, $childCategoryId) {
                    $this->syncSeries($child, $seriesChunk->load('seasons.episodes'), $childCategoryId);
                });
            }
        });
        $child->categories()->whereNotIn('source_category_id', $parentCategoryIds)->delete();
    }

    private function syncSeries(Playlist $child, $seriesChunk, ?int $childCategoryId): array
    {
        $seriesRows = [];
        $seriesSources = [];
        $seriesMap = [];
        foreach ($seriesChunk as $series) {
            $seriesSource = $series->source_series_id ?? 'series-'.$series->id;
            $seriesSources[] = $seriesSource;
            $seriesRows[] = $series->replicate(except: ['id', 'category_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                'playlist_id' => $child->id,
                'category_id' => $childCategoryId,
                'source_series_id' => $seriesSource,
            ];
            $seriesMap[$seriesSource] = $series->seasons;
        }
        $child->series()->upsert($seriesRows, Series::SOURCE_INDEX);
        unset($seriesRows);
        $child->series()->where('category_id', $childCategoryId)->where(function ($q) use ($seriesSources) {
            $q->whereNotIn('source_series_id', $seriesSources)
                ->orWhereNull('source_series_id');
        })->delete();
        $childSeries = $child->series()->where('category_id', $childCategoryId)->whereIn('source_series_id', $seriesSources)->get()->keyBy('source_series_id');
        foreach ($seriesMap as $seriesSource => $seasonsCollection) {
            $childSeriesId = $childSeries[$seriesSource]->id;
            $this->syncSeasons($child, $seasonsCollection, $childSeriesId, $childCategoryId);
        }

        return $seriesSources;
    }

    private function syncSeasons(Playlist $child, $seasonsCollection, int $childSeriesId, ?int $childCategoryId): void
    {
        foreach ($seasonsCollection->chunk(100) as $seasonChunk) {
            $seasonRows = [];
            $seasonSources = [];
            $seasonMap = [];
            foreach ($seasonChunk as $season) {
                $seasonSource = $season->source_season_id ?? 'season-'.$season->id;
                $seasonSources[] = $seasonSource;
                $seasonRows[] = $season->replicate(except: ['id', 'series_id', 'category_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                    'playlist_id' => $child->id,
                    'series_id' => $childSeriesId,
                    'category_id' => $childCategoryId,
                    'source_season_id' => $seasonSource,
                ];
                $seasonMap[$seasonSource] = $season->episodes;
            }
            $child->seasons()->upsert($seasonRows, Season::SOURCE_INDEX);
            unset($seasonRows);
            $child->seasons()->where('series_id', $childSeriesId)->whereNotIn('source_season_id', $seasonSources)->delete();
            $childSeasons = $child->seasons()->where('series_id', $childSeriesId)->whereIn('source_season_id', $seasonSources)->get()->keyBy('source_season_id');
            foreach ($seasonMap as $seasonSource => $episodesCollection) {
                $childSeasonId = $childSeasons[$seasonSource]->id;
                $this->syncEpisodes($child, $episodesCollection, $childSeriesId, $childSeasonId);
            }
        }
    }

    private function syncEpisodes(Playlist $child, $episodesCollection, int $childSeriesId, int $childSeasonId): void
    {
        foreach ($episodesCollection->chunk(100) as $episodeChunk) {
            $episodeRows = [];
            $episodeSources = [];
            foreach ($episodeChunk as $episode) {
                $episodeSource = $episode->source_episode_id ?? 'ep-'.$episode->id;
                $episodeSources[] = $episodeSource;
                $episodeRows[] = $episode->replicate(except: ['id', 'season_id', 'series_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                    'playlist_id' => $child->id,
                    'series_id' => $childSeriesId,
                    'season_id' => $childSeasonId,
                    'source_episode_id' => $episodeSource,
                ];
            }
            $child->episodes()->upsert($episodeRows, ['playlist_id', 'source_episode_id']);
            unset($episodeRows);
            $child->episodes()->where('season_id', $childSeasonId)->whereNotIn('source_episode_id', $episodeSources)->delete();
        }
    }

    /**
     * @param  array<int, array{channel_id:int, attributes:array<string, mixed>, failover_playlist_id:?int, failover_source_id:?string}>  $pendingFailovers
     */
    private function syncUngroupedChannels(Playlist $parent, Playlist $child, array &$pendingFailovers): void
    {
        $ungroupedSources = [];
        $parent->channels()->whereNull('group_id')->with('failovers.channelFailover')->chunkById(100, function ($channels) use ($child, &$ungroupedSources, &$pendingFailovers) {
            $rows = [];
            $failovers = [];
            foreach ($channels as $channel) {
                $source = $channel->source_id ?? 'ch-'.$channel->id;
                $ungroupedSources[] = $source;
                $rows[] = $channel->replicate(except: ['id', 'group_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes() + [
                    'playlist_id' => $child->id,
                    'group_id' => null,
                    'source_id' => $source,
                ];
                $failovers[$source] = $channel->failovers;
            }
            $child->channels()->upsert($rows, ['playlist_id', 'source_id']);
            unset($rows);
            $childChannels = $child->channels()->whereNull('group_id')->whereIn('source_id', array_keys($failovers))->get()->keyBy('source_id');
            foreach ($failovers as $source => $items) {
                $childChannel = $childChannels[$source];
                $childChannel->failovers()->delete();
                foreach ($items as $failover) {
                    $pendingFailovers[] = [
                        'channel_id' => $childChannel->id,
                        'attributes' => Arr::except($failover->getAttributes(), ['id', 'channel_id', 'created_at', 'updated_at']),
                        'failover_playlist_id' => $failover->channelFailover?->playlist_id,
                        'failover_source_id' => $failover->channelFailover?->source_id,
                    ];
                }
            }
        });
        $child->channels()->whereNull('group_id')->where(function ($q) use ($ungroupedSources) {
            $q->whereNotIn('source_id', $ungroupedSources)
                ->orWhereNull('source_id');
        })->delete();
    }

    /**
     * @param  array<int, array{channel_id:int, attributes:array<string, mixed>, failover_playlist_id:?int, failover_source_id:?string}>  $pendingFailovers
     */
    private function applyFailovers(Playlist $parent, Playlist $child, array $pendingFailovers): void
    {
        foreach ($pendingFailovers as $entry) {
            $childChannelId = $entry['channel_id'];
            $attributes = $entry['attributes'];
            $failoverPlaylistId = $entry['failover_playlist_id'];
            $failoverSourceId = $entry['failover_source_id'] ?? ('ch-'.$attributes['channel_failover_id']);

            if ($failoverPlaylistId === null) {
                Log::warning("SyncPlaylistChildren: Missing failover channel {$attributes['channel_failover_id']} on playlist {$child->id}, preserving original reference");

                $newFailover = new ChannelFailover(Arr::except($attributes, ['id', 'channel_id', 'created_at', 'updated_at']));
                $newFailover->channel_id = $childChannelId;
                $newFailover->external = true;
                $newFailover->save();

                continue;
            }

            if ($failoverPlaylistId !== $parent->id) {
                $newFailover = new ChannelFailover(Arr::except($attributes, ['id', 'channel_id', 'created_at', 'updated_at']));
                $newFailover->channel_id = $childChannelId;
                $newFailover->external = true;
                $newFailover->save();

                continue;
            }

            if ($failoverPlaylistId === $parent->id) {
                $childFailoverId = $this->childChannelMap[$failoverSourceId] ?? null;

                $newFailover = new ChannelFailover(Arr::except($attributes, ['id', 'channel_id', 'created_at', 'updated_at']));
                $newFailover->channel_id = $childChannelId;

                if ($childFailoverId) {
                    $newFailover->channel_failover_id = $childFailoverId;
                } else {
                    Log::warning("SyncPlaylistChildren: Child channel not found for failover source '{$failoverSourceId}' on playlist {$child->id}, preserving parent reference");
                }

                $newFailover->external = true;
                $newFailover->save();

                continue;
            }

            $newFailover = new ChannelFailover(Arr::except($attributes, ['id', 'channel_id', 'created_at', 'updated_at']));
            $newFailover->channel_id = $childChannelId;
            $newFailover->external = true;
            $newFailover->save();
        }
    }

    private function syncUncategorizedSeries(Playlist $parent, Playlist $child): void
    {
        $uncatSeriesIds = [];
        $parent->series()->whereNull('category_id')->chunkById(100, function ($seriesChunk) use ($child, &$uncatSeriesIds) {
            $sources = $this->syncSeries($child, $seriesChunk->load('seasons.episodes'), null);
            $uncatSeriesIds = array_merge($uncatSeriesIds, $sources);
        });
        $child->series()->whereNull('category_id')->where(function ($q) use ($uncatSeriesIds) {
            $q->whereNotIn('source_series_id', $uncatSeriesIds)
                ->orWhereNull('source_series_id');
        })->delete();
    }

    /**
     * @param  array<string, array<int, string>>  $changes
     */
    private function syncDelta(Playlist $parent, Playlist $child, array $changes): void
    {
        $groupKeys = $changes['groups'] ?? [];
        if (! empty($groupKeys)) {
            $present = [];
            foreach ($parent->groups()->lazy() as $group) {
                $key = $group->name_internal
                    ?? (Str::slug($group->name) ?: 'grp-' . $group->id);

                if (! in_array($key, $groupKeys, true)) {
                    continue;
                }

                $present[] = $key;
                $childGroup = $child->groups()->where('name_internal', $key)->first();
                if (! $childGroup) {
                    $childGroup = $child->groups()->newModelInstance();
                    $childGroup->name_internal = $key;
                }
                $childGroup->forceFill($group->only(['name', 'name_internal', 'sort_order', 'user_id', 'is_custom']));
                $childGroup->playlist_id = $child->id;
                $childGroup->save();
            }
            $deleted = array_diff($groupKeys, $present);
            if ($deleted) {
                $child->groups()->whereIn('name_internal', $deleted)->delete();
            }
        }

        $channelSources = $changes['channels'] ?? [];
        if (! empty($channelSources)) {
            $groupKeys = [];
            $pendingFailovers = [];
            foreach ($channelSources as $source) {
                $channel = null;
                if (str_starts_with($source, 'ch-')) {
                    $channel = $parent->channels()->with('failovers.channelFailover', 'group')->find(substr($source, 3));
                    if ($channel && ($channel->source_id ?? 'ch-' . $channel->id) !== $source) {
                        $channel = null;
                    }
                } else {
                    $channel = $parent->channels()->with('failovers.channelFailover', 'group')->where('source_id', $source)->first();
                }

                if ($channel) {
                    $groupKey = $channel->group?->name_internal;
                    if ($groupKey) {
                        $groupKeys[$groupKey] = true;
                        $childGroup = $child->groups()->firstOrNew([
                            'name_internal' => $groupKey,
                        ]);
                        if (! $childGroup->exists) {
                            $childGroup->fill($channel->group->only(['name', 'name_internal', 'sort_order', 'user_id', 'is_custom']));
                            $childGroup->playlist_id = $child->id;
                            $childGroup->save();
                        }
                        $childGroupId = $childGroup->id;
                    } else {
                        $childGroupId = null;
                    }

                    $data = $channel->replicate(except: ['id', 'group_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes();
                    $childChannel = $child->channels()->firstOrNew([
                        'source_id' => $source,
                    ]);
                    $childChannel->fill($data);
                    $childChannel->playlist_id = $child->id;
                    $childChannel->group_id = $childGroupId;
                    $childChannel->source_id = $source;
                    $childChannel->save();
                    $this->childChannelMap[$source] = $childChannel->id;

                    $childChannel->failovers()->delete();
                    foreach ($channel->failovers as $failover) {
                        $pendingFailovers[] = [
                            'channel_id' => $childChannel->id,
                            'attributes' => Arr::except($failover->getAttributes(), ['id', 'channel_id', 'created_at', 'updated_at']),
                            'failover_playlist_id' => $failover->channelFailover?->playlist_id,
                            'failover_source_id' => $failover->channelFailover?->source_id,
                        ];
                    }
                } else {
                    $child->channels()->where('source_id', $source)->delete();
                }
            }

            $this->applyFailovers($parent, $child, $pendingFailovers);

            foreach (array_keys($groupKeys) as $groupKey) {
                $this->syncDelta($parent, $child, ['groups' => [$groupKey]]);
            }
        }

        $categorySources = $changes['categories'] ?? [];
        if (! empty($categorySources)) {
            foreach ($categorySources as $source) {
                $category = null;
                if (str_starts_with($source, 'cat-')) {
                    $category = $parent->categories()->find(substr($source, 4));
                    if ($category && ($category->source_category_id ?? 'cat-' . $category->id) !== $source) {
                        $category = null;
                    }
                } else {
                    $category = $parent->categories()->where('source_category_id', $source)->first();
                }

                if ($category) {
                    $childCategory = $child->categories()->firstOrNew([
                        'source_category_id' => $source,
                    ]);
                    $childCategory->fill($category->replicate(except: ['id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes());
                    $childCategory->playlist_id = $child->id;
                    $childCategory->source_category_id = $source;
                    $childCategory->save();
                } else {
                    $child->categories()->where('source_category_id', $source)->delete();
                }
            }
        }

        $seriesSources = $changes['series'] ?? [];
        if (! empty($seriesSources)) {
            foreach ($seriesSources as $source) {
                $series = null;
                if (str_starts_with($source, 'series-')) {
                    $series = $parent->series()->find(substr($source, 7));
                    if ($series && ($series->source_series_id ?? 'series-' . $series->id) !== $source) {
                        $series = null;
                    }
                } else {
                    $series = $parent->series()->where('source_series_id', $source)->first();
                }

                if ($series) {
                    $categorySource = $series->category?->source_category_id
                        ?? ($series->category_id ? 'cat-'.$series->category_id : null);
                    if ($categorySource) {
                        $this->syncDelta($parent, $child, ['categories' => [$categorySource]]);
                        $childCategoryId = $child->categories()->where('source_category_id', $categorySource)->value('id');
                    } else {
                        $childCategoryId = null;
                    }

                    $childSeries = $child->series()->firstOrNew([
                        'source_series_id' => $source,
                    ]);
                    $childSeries->fill($series->replicate(except: ['id', 'category_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes());
                    $childSeries->playlist_id = $child->id;
                    $childSeries->category_id = $childCategoryId;
                    $childSeries->source_series_id = $source;
                    $childSeries->save();
                } else {
                    $child->series()->where('source_series_id', $source)->delete();
                }
            }
        }

        $seasonSources = $changes['seasons'] ?? [];
        if (! empty($seasonSources)) {
            foreach ($seasonSources as $source) {
                $season = null;
                if (str_starts_with($source, 'season-')) {
                    $season = $parent->seasons()->find(substr($source, 7));
                    if ($season && ($season->source_season_id ?? 'season-' . $season->id) !== $source) {
                        $season = null;
                    }
                } else {
                    $season = $parent->seasons()->where('source_season_id', $source)->first();
                }

                if ($season) {
                    $seriesSource = $season->series?->source_series_id
                        ?? ($season->series_id ? 'series-'.$season->series_id : null);
                    if ($seriesSource) {
                        $this->syncDelta($parent, $child, ['series' => [$seriesSource]]);
                        $childSeriesId = $child->series()->where('source_series_id', $seriesSource)->value('id');
                    } else {
                        $childSeriesId = null;
                    }

                    $childSeason = $child->seasons()->firstOrNew([
                        'source_season_id' => $source,
                    ]);
                    $childSeason->fill($season->replicate(except: ['id', 'series_id', 'category_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes());
                    $childSeason->playlist_id = $child->id;
                    $childSeason->series_id = $childSeriesId;
                    $childSeason->category_id = $child->series()->where('id', $childSeriesId)->value('category_id');
                    $childSeason->source_season_id = $source;
                    $childSeason->save();
                } else {
                    $child->seasons()->where('source_season_id', $source)->delete();
                }
            }
        }

        $episodeSources = $changes['episodes'] ?? [];
        if (! empty($episodeSources)) {
            foreach ($episodeSources as $source) {
                $episode = null;
                if (str_starts_with($source, 'ep-')) {
                    $episode = $parent->episodes()->find(substr($source, 3));
                    if ($episode && ($episode->source_episode_id ?? 'ep-' . $episode->id) !== $source) {
                        $episode = null;
                    }
                } else {
                    $episode = $parent->episodes()->where('source_episode_id', $source)->first();
                }

                if ($episode) {
                    $seasonSource = $episode->season?->source_season_id
                        ?? ($episode->season_id ? 'season-'.$episode->season_id : null);
                    if ($seasonSource) {
                        $this->syncDelta($parent, $child, ['seasons' => [$seasonSource]]);
                        $childSeasonId = $child->seasons()->where('source_season_id', $seasonSource)->value('id');
                        $childSeriesId = $child->seasons()->where('id', $childSeasonId)->value('series_id');
                    } else {
                        $childSeasonId = null;
                        $childSeriesId = null;
                    }

                    $childEpisode = $child->episodes()->firstOrNew([
                        'source_episode_id' => $source,
                    ]);
                    $childEpisode->fill($episode->replicate(except: ['id', 'season_id', 'series_id', 'playlist_id', 'created_at', 'updated_at'])->getAttributes());
                    $childEpisode->playlist_id = $child->id;
                    $childEpisode->series_id = $childSeriesId;
                    $childEpisode->season_id = $childSeasonId;
                    $childEpisode->source_episode_id = $source;
                    $childEpisode->save();
                } else {
                    $child->episodes()->where('source_episode_id', $source)->delete();
                }
            }
        }
    }
}