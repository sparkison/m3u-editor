<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class PlaylistM3uUrl extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.playlist-m3u-url');
    }
}
