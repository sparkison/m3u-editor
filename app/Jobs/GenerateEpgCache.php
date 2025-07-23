<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Services\EpgCacheService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateEpgCache implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 10 minutes to the Job to generate the cache
    // This should be sufficient for most EPGs, but can be adjusted if needed
    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $uuid,
        public bool $notify = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EpgCacheService $cacheService): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if (!$epg) {
        }

        $start = microtime(true);
        $result = $cacheService->cacheEpgData($epg);
        $duration = microtime(true) - $start;

        if ($result) {
            if ($this->notify) {
                $msg = "Cache generated successfully in " . round($duration, 2) . " seconds";
                Notification::make()
                    ->success()
                    ->title("Successfully created cache for \"{$epg->name}\"")
                    ->body($msg)
                    ->broadcast($epg->user)
                    ->sendToDatabase($epg->user);
            }
        } else {
            $error = "Failed to generate cache. You can try to run the cache generation again manually from the EPG management page.";
            Notification::make()
                ->danger()
                ->title("Error creating cache for \"{$epg->name}\"")
                ->body($error)
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }
    }
}
