<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class PlaylistEpgUrl extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.playlist-epg-url');
    }
}
