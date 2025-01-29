<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Models\Playlist;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $lastSynced = Carbon::parse(Playlist::max('synced'));
        $relative = $lastSynced ? $lastSynced->diffForHumans() : null;

        $lastSyncedEpg = Carbon::parse(Epg::max('synced'));
        $epgRelative = $lastSyncedEpg ? $lastSyncedEpg->diffForHumans() : null;
        return [
            Stat::make('Playlists', Playlist::count())
                ->description($relative ? "Last sync $relative" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Groups', Group::count()),
            Stat::make('Total Channels', Channel::count()),
            Stat::make('Enabled Channels', Channel::where('enabled', true)->count()),
            
            Stat::make('EPGs', Epg::count())
                ->description($epgRelative ? "Last sync $epgRelative" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Total EPG Channels', EpgChannel::count()),
            Stat::make('Total EPG Programs', EpgProgramme::count()),
            Stat::make('EPG Mapped Channels', Channel::where('epg_channel_id', '!=', null)->count()),
        ];
    }
}
