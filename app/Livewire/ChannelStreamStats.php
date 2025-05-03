<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ChannelStreamStats extends Component
{
    public array $streamStats = [];

    public function mount(Model $record): void
    {
        $this->streamStats = $record->stream_stats;
    }

    public function placeholder()
    {
        return <<<'HTML'
            <div class="flex items-center space-x-2">
                <x-filament::loading-indicator class="h-5 w-5" />
                <p>Fetching stream stats, hold tight...</p>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.channel-stream-stats');
    }
}
