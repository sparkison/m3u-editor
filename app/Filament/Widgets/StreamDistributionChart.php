<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use Filament\Widgets\ChartWidget;

class StreamDistributionChart extends ChartWidget
{
    protected static ?string $heading = 'Stream Format Distribution';
    protected static ?int $sort = 5;
    protected static ?string $pollingInterval = '60s';
    
    protected function getData(): array
    {
        $formatCounts = SharedStream::active()
            ->selectRaw('format, COUNT(*) as count')
            ->groupBy('format')
            ->pluck('count', 'format')
            ->toArray();

        $labels = [];
        $data = [];
        $colors = [
            'hls' => '#10B981',      // Green
            'dash' => '#3B82F6',     // Blue  
            'ts' => '#8B5CF6',       // Purple
            'mp4' => '#F59E0B',      // Yellow
            'webm' => '#EF4444',     // Red
            'other' => '#6B7280',    // Gray
        ];

        foreach ($formatCounts as $format => $count) {
            $labels[] = strtoupper($format);
            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => array_values($colors),
                    'borderColor' => array_values($colors),
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => 'function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ": " + context.parsed + " (" + percentage + "%)";
                        }'
                    ]
                ]
            ],
        ];
    }
}
