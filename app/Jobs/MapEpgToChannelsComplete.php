<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MapEpgToChannelsComplete implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public Epg $epg,
        public Carbon $start,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        // Notify the user
        $epg = $this->epg;
        $playlist = $this->playlist;
        $title = "Completed processing EPG channel mapping";
        $body = "EPG \"{$epg->name}\" to channel mapping for playlist \"{$playlist->name}\" completed. Mapping took {$completedInRounded} seconds.";
        Notification::make()
            ->success()
            ->title($title)->body($body)
            ->broadcast($epg->user)
            ->sendToDatabase($epg->user);
    }
}
