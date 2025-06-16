<?php

namespace App\Listeners;

use App\Events\StreamingStarted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class StreamingStartListener
{
    /**
     * Handle the event.
     */
    public function handle(StreamingStarted $event): void
    {
        Log::channel('ffmpeg')->info('StreamingStarted event fired for playlist: ' . $event->playlistId);

        // Additional logic can be added here if needed...
    }
}
