<?php

namespace App\Jobs;

use Throwable;
use App\Enums\Status;
use App\Models\Epg;
use App\Services\EpgCacheService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateEpgCache implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 15 minutes to the Job to generate the cache
    // This should be sufficient for most EPGs, but can be adjusted if needed
    public $timeout = 60 * 15;

    // Allow up to 2 attempts (1 retry)
    public $tries = 2;

    // Delay between attempts if it fails
    public $backoff = 300; // 5 minutes

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
            Log::error("EPG with UUID {$this->uuid} not found for cache generation.");
            return;
        }

        // Set memory and time limits for large EPG files
        ini_set('memory_limit', '2G');
        set_time_limit(0); // No time limit
        $start = microtime(true);
        $epg->update([
            'status' => Status::Processing,
        ]);
        $result = $cacheService->cacheEpgData($epg);
        $epg->update([
            'status' => Status::Completed,
        ]);
        $duration = microtime(true) - $start;

        if ($result) {
            if ($this->notify) {
                $msg = "Cache generated successfully in " . round($duration, 2) . " seconds";
                Notification::make()
                    ->success()
                    ->title("EPG cache created for \"{$epg->name}\"")
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

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if ($epg) {
            $epg->update([
                'status' => Status::Failed,
                'cache_progress' => 0,
            ]);

            Log::error("EPG cache generation failed for {$epg->name}: {$exception->getMessage()}");

            // Always send failure notification
            Notification::make()
                ->danger()
                ->title("EPG cache generation failed for \"{$epg->name}\"")
                ->body("Error: {$exception->getMessage()}")
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }
    }
}
