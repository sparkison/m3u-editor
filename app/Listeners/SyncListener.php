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

        // Create jobs array, with cache generation as the first job
        $jobs = [new GenerateEpgCache($epg->uuid, notify: true)];

        // Check if there are any sync jobs that should be re-run
        EpgMap::where([
            ['epg_id', '=', $epg->id],
            ['recurring', '=', true],
            ['playlist_id', '!=', null],
        ])->get()->each(function ($map) use (&$jobs) {
            $jobs[] = new MapPlaylistChannelsToEpg(
                epg: $map->epg_id,
                playlist: $map->playlist_id,
                epgMapId: $map->id,
            );
        });

        // Bus jobs
        Bus::chain($jobs)
            ->onConnection('redis') // force to use redis connection
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($epg) {
                $error = "Error post-processing \"{$epg->name}\": {$e->getMessage()}";
                Notification::make()
                    ->danger()
                    ->title("Error post-processing \"{$epg->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($epg->user);
                Notification::make()
                    ->danger()
                    ->title("Error post-processing \"{$epg->name}\"")
                    ->body($error)
                    ->sendToDatabase($epg->user);
            })->dispatch();
    }
}
