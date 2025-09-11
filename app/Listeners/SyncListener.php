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
use Illuminate\Support\Facades\Bus;

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

            // Build mapping jobs for recurring configurations where the
            // associated EPG has completed syncing.
            $mappingJobs = [];
            $event->model->epgMaps()
                ->with('epg')
                ->where('recurring', true)
                ->get()
                ->each(function (EpgMap $map) use (&$mappingJobs) {
                    if ($map->epg && $map->epg->status === Status::Completed) {
                        $mappingJobs[] = new MapPlaylistChannelsToEpg(
                            epg: $map->epg_id,
                            playlist: $map->playlist_id,
                            epgMapId: $map->id,
                        );
                    }
                });

            if (! $event->model->parent_id && $event->model->children()->exists()) {
                // Parent sync has finished; chain child imports, EPG mapping and
                // final child synchronization.
                $parent = $event->model;
                $jobs = [];

                $parent->children()->get()->each(function ($child) use (&$jobs) {
                    $jobs[] = new ProcessM3uImport($child, true);
                });

                $jobs = array_merge($jobs, $mappingJobs);
                $jobs[] = new SyncPlaylistChildren($parent);

                $lock = Cache::lock("playlist-sync-children:{$parent->id}", 300);
                if ($lock->get()) {
                    Bus::chain($jobs)->dispatch();
                    // Lock will be released by SyncPlaylistChildren once completed
                }
            } else {
                // No children: dispatch mapping jobs immediately.
                foreach ($mappingJobs as $job) {
                    dispatch($job);
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
