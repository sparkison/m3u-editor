<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class XtreamApiInfo extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.xtream-api-info');
    }
}
