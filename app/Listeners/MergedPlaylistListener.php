<?php

namespace App\Listeners;

use App\Events\MergedPlaylistCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class MergedPlaylistListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(MergedPlaylistCreated $event): void
    {
        //
    }
}
