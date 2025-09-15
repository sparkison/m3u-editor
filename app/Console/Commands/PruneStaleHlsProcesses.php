<?php

namespace App\Console\Commands;

use Exception;
use App\Services\SharedStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'app:hls-prune {--threshold=8}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently, or cleanup clients for Shared Streaming with no recent activity.';

    public function handle(
        SharedStreamService $sharedStreamService,
    ) {
        // Get the threshold from the command line option (default is 8 seconds)
        $threshold = (int)$this->option('threshold');

        // If Shared Streaming is enabled, we only need to prune stale clients
        // The stream will be automatically cleaned up when the client count goes to zero
        try {
            // Need to remove any stale clients
            // We can be pretty aggressive with this, as the timestamp will be updated frequently for active connections
            $activeClients = $sharedStreamService->getAllActiveClients();
            $removedClients = 0;
            foreach ($activeClients as $client) {
                if (isset($client['last_activity_at']) && time() - $client['last_activity_at'] > $threshold) {
                    Log::channel('ffmpeg')->debug("StreamMonitor: Removing stale client {$client['client_id']} from stream {$client['stream_id']}");
                    $sharedStreamService->removeClient($client['stream_id'], $client['client_id']);
                    $removedClients++;
                }
            }
            if ($removedClients > 0) {
                Log::channel('ffmpeg')->debug(
                    "ClientMonitor: Updated stats - Client count: " . count($activeClients) . ", " .
                        "stale clients removed: {$removedClients}."
                );
            }
        } catch (Exception $e) {
            Log::channel('ffmpeg')->error('StreamMonitor: Error during monitoring update: ' . $e->getMessage());
            throw $e;
        }
    }
}
