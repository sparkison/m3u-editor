<?php

namespace App\Listeners;

use App\Events\SyncCompleted;
use App\Jobs\RunPostProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SyncListener implements ShouldQueue
{
    /**
     * Handle the event.
     * 
     * @param SyncCompleted $event
     */
    public function handle(SyncCompleted $event): void
    {
        if ($event->playlist) {
            $event->playlist->postProcesses()->where([
                ['type', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess($postProcess, $event->playlist));
            });
        }
        if ($event->epg) {
            $event->epg->postProcesses()->where([
                ['type', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess($postProcess, $event->epg));
            });
        }
    }
}
