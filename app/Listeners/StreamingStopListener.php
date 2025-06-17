<?php

namespace App\Listeners;

use App\Events\StreamingStopped;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class StreamingStopListener
{
    /**
     * Handle the event.
     */
    public function handle(StreamingStopped $event): void
    {
        Log::channel('ffmpeg')->info('StreamingStopped event fired for playlist: ' . $event->playlistId);

        // Notify MediaFlow microservice if enabled
        $this->notifyMediaFlowMicroservice('stream_stopped', [
            'playlist_id' => $event->playlistId,
            'timestamp' => now()->toISOString(),
        ]);

        // Additional logic can be added here if needed...
    }
    
    /**
     * Notify the MediaFlow microservice about stream events
     */
    private function notifyMediaFlowMicroservice(string $event, array $data): void
    {
        try {
            $microserviceUrl = config('app.mediaflow_microservice_url', 'http://localhost:3001');
            
            Http::timeout(2)->post("{$microserviceUrl}/events", [
                'event' => $event,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // Don't fail the main operation if microservice is unavailable
            Log::channel('ffmpeg')->debug('MediaFlow microservice notification failed: ' . $e->getMessage());
        }
    }
}
