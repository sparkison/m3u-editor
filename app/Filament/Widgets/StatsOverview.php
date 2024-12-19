<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
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
        return [
            Stat::make('Playlists', Playlist::count())
                ->description($relative ? "Last sync $relative" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Groups', Group::count()),
            Stat::make('Channels', Channel::count()),
        ];
    }
}
