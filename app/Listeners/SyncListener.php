<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\RunPostProcess;
use App\Jobs\ProcessM3uImport;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Epg;
use App\Models\EpgMap;
use Illuminate\Support\Facades\Cache;

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

            if (! $event->model->parent_id && $event->model->children()->exists()) {
                // Parent sync has finished; trigger provider sync for each child
                $event->model->children()->get()->each(function ($child) {
                    dispatch(new ProcessM3uImport($child, true));
                });
            } elseif ($event->model->parent_id && ! $event->model->parent->processing) {
                $parent = $event->model->parent;
                $lock = Cache::lock("playlist-sync-children:{$parent->id}", 300);

                if ($lock->get()) {
                    $pending = $parent->children()
                        ->where('status', '!=', Status::Completed)
                        ->exists();

                    if (! $pending) {
                        dispatch(new SyncPlaylistChildren($parent));
                        // Lock will be released by SyncPlaylistChildren once completed
                    } else {
                        $lock->release();
                    }
                }
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
