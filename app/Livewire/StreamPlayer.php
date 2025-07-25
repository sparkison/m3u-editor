<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

class StreamPlayer extends Component
{
    public $streamUrl = '';
    public $streamFormat = 'ts';
    public $channelTitle = '';
    public $channelLogo = '';
    public $showModal = false;
    public $playerId;

    public function mount()
    {
        $this->playerId = 'stream-player-' . uniqid();
    }

    #[On('openStreamPlayer')]
    public function openPlayer($url = '', $format = 'ts', $title = '', $display_name = '', $logo = '')
    {
        $this->streamUrl = $url;
        $this->streamFormat = $format ?: 'ts';
        $this->channelTitle = $title ?: $display_name ?: 'Unknown Channel';
        $this->channelLogo = $logo;
        $this->showModal = true;
    }

    #[On('closeStreamPlayer')]
    public function closePlayer()
    {
        $this->showModal = false;
        $this->streamUrl = '';
        $this->streamFormat = 'ts';
        $this->channelTitle = '';
        $this->channelLogo = '';
        
        // Dispatch event to cleanup player
        $this->dispatch('cleanupPlayer', playerId: $this->playerId);
    }

    public function render()
    {
        return view('livewire.stream-player');
    }
}
