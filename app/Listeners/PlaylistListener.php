<?php

namespace App\Listeners;

use App\Events\PlaylistCreated;
use App\Jobs\ProcessM3uImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PlaylistListener implements ShouldQueue
{
    /**
     * Handle the event.
     * 
     * @param PlaylistCreated $event
     */
    public function handle(PlaylistCreated $event): void
    {
        dispatch(new ProcessM3uImport($event->playlist));
    }
}
