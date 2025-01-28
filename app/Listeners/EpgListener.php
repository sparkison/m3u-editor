<?php

namespace App\Listeners;

use App\Events\EpgCreated;
use App\Jobs\ProcessEpgImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class EpgListener implements ShouldQueue
{
    /**
     * Handle the event.
     * 
     * @param EpgCreated $event
     */
    public function handle(EpgCreated $event): void
    {
        dispatch(new ProcessEpgImport($event->epg));
    }
}
