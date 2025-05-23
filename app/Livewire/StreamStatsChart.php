<?php

namespace App\Livewire;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Redis;

class StreamStatsChart extends ChartWidget
{
    protected static ?string $heading         = 'MPEG-TS Stream Stats';
    protected static ?string $description     = 'Bitrate and FPS stats for the MPEG-TS stream.';
    public static     ?string $pollingInterval = '1s';

    public string $title;
    public string $subheading;
    public string $streamId;

    protected function getData(): array
    {
        $labels  = Redis::lrange("mpts:channel_hist:{$this->streamId}:timestamps", 0, -1);
        $bitrate = array_map('floatval', Redis::lrange("mpts:channel_hist:{$this->streamId}:bitrate", 0, -1));
        $fps     = array_map('floatval', Redis::lrange("mpts:channel_hist:{$this->streamId}:fps",     0, -1));

        return [
            'labels'   => $labels ?: [],
            'datasets' => [
                [
                    'label'           => 'Bitrate (kbits/s)',
                    'data'            => $bitrate,
                    'borderColor'     => 'rgba(75, 192, 192, 1)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.4)',
                    'yAxisID'         => 'y',
                    'fill'            => false,
                ],
                [
                    'label'           => 'FPS',
                    'data'            => $fps,
                    'borderColor'     => 'rgba(255, 159, 64, 1)',
                    'backgroundColor' => 'rgba(255, 159, 64, 0.4)',
                    'yAxisID'         => 'y1',
                    'fill'            => false,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'type'        => 'linear',
                    'position'    => 'left',
                    'beginAtZero' => true,
                    'title'       => [
                        'display' => true,
                        'text'    => 'Bitrate (kbits/s)',
                    ],
                ],
                'y1' => [
                    'type'              => 'linear',
                    'position'          => 'right',
                    'beginAtZero'       => true,
                    'grid'              => ['drawOnChartArea' => false],
                    'title'             => [
                        'display' => true,
                        'text'    => 'FPS',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
