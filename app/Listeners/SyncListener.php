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
        if ($event->model instanceof \App\Models\Playlist) {
            $event->model->postProcesses()->where([
                ['type', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess($postProcess, $event->model));
            });
        }
        if ($event->model instanceof \App\Models\Epg) {
            $event->model->postProcesses()->where([
                ['type', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess($postProcess, $event->model));
            });
        }
    }
}
