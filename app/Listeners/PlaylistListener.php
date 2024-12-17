<?php

namespace App\Listeners;

use App\Events\PlaylistCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PlaylistListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     * 
     * @param PlaylistCreated $event
     */
    public function handle(PlaylistCreated $event): void
    {
        // $event->playlist;
        // @TODO - process the playlist...
    }
}
