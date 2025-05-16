<?php

namespace App\Livewire;

use App\Models\Channel;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Redis;

class StreamStatsChart extends ChartWidget
{
    protected static ?string $heading = 'Chart';
    public static ?string $pollingInterval = '1s';
    public string $streamId;
    public ?Channel $channel = null;

    protected function getData(): array
    {
        $this->channel = Channel::find($this->streamId);
        self::$heading = $this->channel ? $this->channel->title : 'Stream Stats';

        // fetch the history lists
        $labels  = Redis::lrange("mpts:hist:{$this->streamId}:timestamps", 0, -1);
        $bitrate = Redis::lrange("mpts:hist:{$this->streamId}:bitrate",    0, -1);
        $fps     = Redis::lrange("mpts:hist:{$this->streamId}:fps",        0, -1);
        return [
            'labels'   => $labels ?: [],
            'datasets' => [
                [
                    'label' => 'Bitrate (kbits/s)',
                    'data'  => array_map('floatval', $bitrate),
                ],
                [
                    'label' => 'FPS',
                    'data'  => array_map('floatval', $fps),
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
