<?php

namespace App\Filament\Resources\PlaylistResource\Widgets;

use App\Models\Playlist;
use App\Services\XtreamService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class PlaylistStatsWidget extends BaseWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        $playlist = Playlist::find($this->record?->id);
        if (!$playlist) {
            return [];
        }

        $stats = [];
        if ($playlist->enable_proxy) {
            $activeStreams = Redis::get("active_streams:{$playlist->id}") ?? 0;
            $availableStreams = $playlist->available_streams ?? 0;
            if ($availableStreams === 0) {
                $availableStreams = "∞";
            }
            $maxStreamsReached = $activeStreams > 0 && $activeStreams >= $availableStreams;
            $stats[] = Stat::make('proxy_streams', "$activeStreams/$availableStreams")
                ->label('Proxy Connections')
                ->description('Active vs. available')
                ->descriptionIcon('heroicon-o-chart-bar', 'before')
                ->color($maxStreamsReached ? 'danger' : 'primary');
        }
        if ($playlist->xtream) {
            $xtreamStats = $this->getXtreamStats($playlist);
            if (!empty($xtreamStats)) {
                $stats = array_merge($stats, $xtreamStats);
            }
        }

        return $stats;
    }

    private function getXtreamStats(Playlist $playlist): array
    {
        $cacheKey = "xtream_stats:{$playlist->id}";
        $xtreamInfo = Cache::get($cacheKey, null);
        if (!$xtreamInfo) {
            // If no cache, initialize XtreamService
            $xtream = XtreamService::make($playlist);
            if (!$xtream) {
                // Try and fetch from the playlist data directly
                $xtreamInfo = $playlist->xtream_info;
            } else {
                // Prefer live data from XtreamService
                $xtreamInfo = $xtream->userInfo();
            }
            if (!$xtreamInfo) {
                return [];
            }
        }
        Cache::put($cacheKey, $xtreamInfo, now()->addMinutes(10)); // Cache for 10 minutes

        $maxConnections = $xtreamInfo['user_info']['max_connections'] ?? 1;
        $activeConnections = $xtreamInfo['user_info']['active_cons'] ?? 0;
        $expires = $xtreamInfo['user_info']['exp_date'] ?? null;
        $expiresIn24HoursOrLess = false;
        if ($expires) {
            $expires = Carbon::createFromTimestamp($expires);
            $expiresIn24HoursOrLess = $expires->isToday() || $expires->isTomorrow();
        }
        return [
            Stat::make('active_connections', "$activeConnections/$maxConnections")
                ->label('Provider Connections')
                ->description('Active vs. available')
                ->descriptionIcon('heroicon-o-chart-bar', 'before')
                ->color($activeConnections >= $maxConnections ? 'danger' : 'primary'),
            Stat::make('expires', $expires->diffForHumans())
                ->label('Expires')
                ->description('Account expires: ' . $expires->toDateTimeString())
                ->descriptionIcon('heroicon-o-calendar', 'before')
                ->color($expiresIn24HoursOrLess ? 'danger' : 'primary'),
        ];
    }
}
