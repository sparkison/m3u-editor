<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use App\Services\StreamMonitorService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SystemHealthWidget extends BaseWidget
{
    protected static ?string $heading = 'System Health';
    protected static ?int $sort = 4;
    protected static ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $monitorService = app(StreamMonitorService::class);
        $systemStats = $monitorService->getSystemStats();
        
        // Get unhealthy streams
        $unhealthyStreams = SharedStream::where('health_status', '!=', 'healthy')->count();
        $totalActiveStreams = SharedStream::active()->count();
        
        // Calculate system health percentage
        $healthPercentage = $totalActiveStreams > 0 
            ? round((($totalActiveStreams - $unhealthyStreams) / $totalActiveStreams) * 100, 1)
            : 100;

        // Memory usage
        $memoryUsage = $systemStats['memory_usage']['percentage'] ?? 0;
        
        // CPU Load (average from load average)
        $loadAverage = $systemStats['load_average']['1min'] ?? 0;
        $cpuCount = $systemStats['cpu_count'] ?? 1;
        $cpuUsagePercent = round(($loadAverage / $cpuCount) * 100, 1);

        // Redis status
        $redisConnected = $systemStats['redis_connected'] ?? false;

        return [
            Stat::make('System Health', $healthPercentage . '%')
                ->description($unhealthyStreams > 0 ? "{$unhealthyStreams} streams need attention" : 'All systems operational')
                ->descriptionIcon($healthPercentage >= 95 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($healthPercentage >= 95 ? 'success' : ($healthPercentage >= 80 ? 'warning' : 'danger')),

            Stat::make('Memory Usage', $memoryUsage . '%')
                ->description('System RAM utilization')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($memoryUsage < 80 ? 'success' : ($memoryUsage < 90 ? 'warning' : 'danger')),

            Stat::make('CPU Load', $cpuUsagePercent . '%')
                ->description("Load avg: {$loadAverage}")
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color($cpuUsagePercent < 70 ? 'success' : ($cpuUsagePercent < 90 ? 'warning' : 'danger')),

            Stat::make('Redis Status', $redisConnected ? 'Connected' : 'Disconnected')
                ->description('Cache & session storage')
                ->descriptionIcon($redisConnected ? 'heroicon-m-signal' : 'heroicon-m-signal-slash')
                ->color($redisConnected ? 'success' : 'danger'),
        ];
    }
}
