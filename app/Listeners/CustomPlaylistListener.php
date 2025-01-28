<?php

namespace App\Listeners;

use App\Events\CustomPlaylistCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class CustomPlaylistListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(CustomPlaylistCreated $event): void
    {
        //
    }
}
