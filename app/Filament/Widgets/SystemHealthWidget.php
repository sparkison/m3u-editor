<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use App\Services\StreamMonitorService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SystemHealthWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected ?string $pollingInterval = '10s';

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
        
        // CPU Load (proper interpretation of load average)
        $loadAverage = round($systemStats['load_average']['1min'] ?? 0, 2);
        $cpuCount = $systemStats['cpu_count'] ?? 1;
        
        // Load average is the number of processes that are running or waiting to run
        // On a system with N cores, a load average of N means 100% utilization
        // Load average can exceed the number of cores (indicating overload)
        $loadPercentage = round(($loadAverage / $cpuCount) * 100, 1);
        
        // For display purposes, we can show both the raw load average and percentage
        $loadDescription = "Load avg: {$loadAverage} ({$cpuCount} cores)";
        
        // Color coding based on load relative to cores:
        // Green: < 70% of cores utilized
        // Yellow: 70-100% of cores utilized  
        // Red: > 100% of cores utilized (overloaded)
        $loadColor = 'success';
        if ($loadPercentage >= 100) {
            $loadColor = 'danger';
        } elseif ($loadPercentage >= 70) {
            $loadColor = 'warning';
        }

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

            Stat::make('CPU Load', $loadPercentage . '%')
                ->description($loadDescription)
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color($loadColor),

            Stat::make('Redis Status', $redisConnected ? 'Connected' : 'Disconnected')
                ->description('Cache & session storage')
                ->descriptionIcon($redisConnected ? 'heroicon-m-signal' : 'heroicon-m-signal-slash')
                ->color($redisConnected ? 'success' : 'danger'),
        ];
    }
}
