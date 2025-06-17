<?php

namespace App\Filament\Widgets;

use App\Services\StreamMonitorService;
use Filament\Widgets\ChartWidget;

class SystemResourcesChart extends ChartWidget
{
    protected static ?string $heading = 'System Resources';
    protected static ?int $sort = 8;
    protected static ?string $pollingInterval = '5s';
    protected int | string | array $columnSpan = 'full';
    
    protected function getData(): array
    {
        $monitorService = app(StreamMonitorService::class);
        $systemStats = $monitorService->getSystemStats();
        
        // Get historical system metrics (simplified for demo)
        $time = now();
        $labels = [];
        $cpuData = [];
        $memoryData = [];
        $diskData = [];
        
        // Generate last 20 data points (simulated for real-time effect)
        for ($i = 19; $i >= 0; $i--) {
            $labels[] = $time->copy()->subMinutes($i)->format('H:i');
            
            // In a real implementation, these would come from stored metrics
            $cpuData[] = $this->getCurrentCpuUsage($systemStats);
            $memoryData[] = $systemStats['memory_usage']['percentage'] ?? 0;
            $diskData[] = $systemStats['disk_space']['percentage'] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'CPU Usage (%)',
                    'data' => $cpuData,
                    'borderColor' => '#EF4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'tension' => 0.4,
                    'fill' => false,
                ],
                [
                    'label' => 'Memory Usage (%)',
                    'data' => $memoryData,
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension' => 0.4,
                    'fill' => false,
                ],
                [
                    'label' => 'Disk Usage (%)',
                    'data' => $diskData,
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension' => 0.4,
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'title' => [
                        'display' => true,
                        'text' => 'Usage Percentage (%)'
                    ]
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Time'
                    ]
                ]
            ],
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ]
            ],
            'animation' => [
                'duration' => 750,
            ],
        ];
    }

    private function getCurrentCpuUsage(array $systemStats): float
    {
        $loadAverage = $systemStats['load_average']['1min'] ?? 0;
        $cpuCount = $systemStats['cpu_count'] ?? 1;
        
        return round(($loadAverage / $cpuCount) * 100, 1);
    }
}
