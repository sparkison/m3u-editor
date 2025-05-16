<?php

namespace App\Filament\Pages;

use App\Livewire\StreamStatsChart;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Redis;

class Stats extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Stats';
    protected static ?string $title           = 'Stats';
    protected ?string        $subheading      = 'Start streaming a channel to see the stats.';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int    $navigationSort  = 99;
    protected static string  $view            = 'filament.pages.stats';

    protected function getHeaderWidgets(): array
    {
        // Fetch all currently streaming channel IDs
        $activeIds = Redis::smembers('mpts:active_ids');

        // Dynamically spawn one StreamStatsChart per channel
        return collect($activeIds)
            ->map(fn(string $id) => StreamStatsChart::make([
                'streamId'   => $id,
                'columnSpan' => 6, // optional: two widgets per row
            ]))->toArray();
    }
}
