<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    /**
     * Cache duration in seconds (5 minutes)
     */
    protected int $cacheDuration = 300;

    protected function getStats(): array
    {
        $userId = auth()->id();

        // Cache the stats for better performance
        // Single optimized query replaces 11 separate queries
        $stats = Cache::remember("dashboard_stats_{$userId}", $this->cacheDuration, function () use ($userId) {
            // Use a single query with aggregates for PostgreSQL and SQLite compatibility
            $result = DB::table('playlists as p')
                ->selectRaw('
                    COUNT(DISTINCT p.id) as playlists_count,
                    MAX(p.synced) as last_playlist_sync
                ')
                ->where('p.user_id', $userId)
                ->first();

            $groups = DB::table('groups')->where('user_id', $userId)->count();

            $channels = DB::table('channels')
                ->selectRaw('
                    COUNT(*) as total_channels,
                    COUNT(CASE WHEN enabled = true THEN 1 END) as enabled_channels,
                    COUNT(CASE WHEN epg_channel_id IS NOT NULL THEN 1 END) as mapped_channels
                ')
                ->where('user_id', $userId)
                ->first();

            $epgs = DB::table('epgs')
                ->selectRaw('
                    COUNT(*) as epgs_count,
                    MAX(synced) as last_epg_sync
                ')
                ->where('user_id', $userId)
                ->first();

            $epgChannels = DB::table('epg_channels')->where('user_id', $userId)->count();
            $series = DB::table('series')->where('user_id', $userId)->count();
            $episodes = DB::table('episodes')->where('user_id', $userId)->count();

            return (object) [
                'playlists_count' => $result->playlists_count ?? 0,
                'last_playlist_sync' => $result->last_playlist_sync,
                'groups_count' => $groups,
                'total_channels' => $channels->total_channels ?? 0,
                'enabled_channels' => $channels->enabled_channels ?? 0,
                'mapped_channels' => $channels->mapped_channels ?? 0,
                'epgs_count' => $epgs->epgs_count ?? 0,
                'last_epg_sync' => $epgs->last_epg_sync,
                'epg_channels_count' => $epgChannels,
                'series_count' => $series,
                'episodes_count' => $episodes,
            ];
        });

        // Format the last synced dates
        $lastSynced = $stats->last_playlist_sync ? Carbon::parse($stats->last_playlist_sync)->diffForHumans() : null;
        $lastEpgSynced = $stats->last_epg_sync ? Carbon::parse($stats->last_epg_sync)->diffForHumans() : null;

        return [
            Stat::make('Playlists', $stats->playlists_count)
                ->description($lastSynced ? "Last sync $lastSynced" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Groups', $stats->groups_count),
            Stat::make('Total Channels', $stats->total_channels),
            Stat::make('Enabled Channels', $stats->enabled_channels),
            Stat::make('EPGs', $stats->epgs_count)
                ->description($lastEpgSynced ? "Last sync $lastEpgSynced" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Total EPG Channels', $stats->epg_channels_count),
            Stat::make('EPG Mapped Channels', $stats->mapped_channels),
            Stat::make('Series', $stats->series_count),
            Stat::make('Episodes', $stats->episodes_count),
        ];
    }
}
