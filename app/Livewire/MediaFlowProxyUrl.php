<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class MediaFlowProxyUrl extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.media-flow-proxy-url');
    }
}
