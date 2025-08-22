<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SharedStreamStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $activeStreams = SharedStream::active()->count();
        $totalClients = SharedStream::active()->sum('client_count');
        $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');
        $totalStreams = SharedStream::count();

        // Get peak metrics for the last 24 hours
        $peakData = SharedStreamStat::selectRaw('MAX(client_count) as peak_clients, MAX(bandwidth_kbps) as peak_bandwidth')
                                   ->where('recorded_at', '>=', now()->subDay())
                                   ->first();

        $peakClients = $peakData?->peak_clients ?? 0;
        $peakBandwidth = $peakData?->peak_bandwidth ?? 0;

        return [
            Stat::make('Active Shared Streams', $activeStreams)
                ->description($totalStreams > 0 ? "of {$totalStreams} total" : 'No streams created yet')
                ->descriptionIcon('heroicon-m-signal')
                ->color($activeStreams > 0 ? 'success' : 'gray'),

            Stat::make('Connected Clients', $totalClients)
                ->description($peakClients > 0 ? "Peak today: {$peakClients}" : 'No peak data')
                ->descriptionIcon('heroicon-m-users')
                ->color($totalClients > 0 ? 'info' : 'gray'),

            Stat::make('Total Bandwidth', $this->formatBandwidth($totalBandwidth))
                ->description($peakBandwidth > 0 ? "Peak: " . $this->formatBandwidth($peakBandwidth) : 'No peak data')
                ->descriptionIcon('heroicon-m-bolt')
                ->color($totalBandwidth > 0 ? 'warning' : 'gray'),

            Stat::make('Avg Clients/Stream', $activeStreams > 0 ? round($totalClients / $activeStreams, 1) : '0')
                ->description('Current efficiency')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($totalClients > 0 ? 'primary' : 'gray'),
        ];
    }

    protected function formatBandwidth(int $kbps): string
    {
        if ($kbps === 0) {
            return '0 kbps';
        }

        if ($kbps >= 1000) {
            return round($kbps / 1000, 1) . ' Mbps';
        }

        return $kbps . ' kbps';
    }
}
