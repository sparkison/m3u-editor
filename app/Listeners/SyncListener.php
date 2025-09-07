<?php

namespace App\Listeners;

use Throwable;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\RunPostProcess;
use App\Models\Epg;
use App\Models\EpgMap;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SyncListener
{
    /**
     * Handle the event.
     */
    public function handle(SyncCompleted $event): void
    {
        if ($event->model instanceof \App\Models\Playlist) {
            $lastSync = $event->model->syncStatuses()->first();
            $event->model->postProcesses()->where([
                ['event', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event, $lastSync) {
                dispatch(new RunPostProcess(
                    $postProcess,
                    $event->model,
                    $lastSync
                ));
            });

            // Automatically map playlist channels to the EPG if recurring
            // mappings have been configured. Only run the mapping when the
            // associated EPG has completed syncing to avoid unnecessary jobs.
            $event->model->epgMaps()
                ->with('epg')
                ->where('recurring', true)
                ->get()
                ->each(function (EpgMap $map) {
                    if ($map->epg && $map->epg->status === Status::Completed) {
                        dispatch(new MapPlaylistChannelsToEpg(
                            epg: $map->epg_id,
                            playlist: $map->playlist_id,
                            epgMapId: $map->id,
                        ));
                    }
                });

            if (!$event->model->parent_id && $event->model->children()->exists()) {
                $event->model->children()->update([
                    'status' => Status::Pending,
                    'processing' => false,
                ]);
                dispatch(new \App\Jobs\SyncPlaylistChildren($event->model));
            } elseif ($event->model->parent_id && ! $event->model->parent->processing) {
                dispatch(new \App\Jobs\SyncPlaylistChildren($event->model->parent));
            }
        }
        if ($event->model instanceof \App\Models\Epg) {
            $event->model->postProcesses()->where([
                ['event', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess(
                    $postProcess,
                    $event->model
                ));
            });

            // Generate EPG cache if sync was successful
            if ($event->model->status === Status::Completed) {
                $this->postProcessEpg($event->model);
            }
        }
    }

    /**
     * Post-process an EPG after a successful sync.
     */
    private function postProcessEpg(Epg $epg)
    {
        // Update status to Processing (so UI components will continue to refresh) and dispatch cache job
        $epg->update(['status' => Status::Processing]);

        // Dispatch cache generation job
        dispatch(new GenerateEpgCache($epg->uuid, notify: true));
    }
}
