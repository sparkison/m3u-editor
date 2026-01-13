<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NetworkBroadcastHeal extends Command
{
    protected $signature = 'network:broadcast:heal {--dry-run : Do not modify state, only report}';

    protected $description = 'Heal stale broadcast state for networks whose FFmpeg processes are gone';

    public function handle(NetworkBroadcastService $service): int
    {
        $dryRun = $this->option('dry-run');

        $networks = Network::whereNotNull('broadcast_pid')->get();

        $this->info('Checking '.count($networks).' networks with broadcast pids');

        foreach ($networks as $network) {
            if (! $service->isProcessRunning($network)) {
                $this->line("Stale broadcast found for network {$network->id} ({$network->uuid}) pid={$network->broadcast_pid}");

                if (! $dryRun) {
                    $old = $network->broadcast_pid;
                    $network->update([
                        'broadcast_started_at' => null,
                        'broadcast_pid' => null,
                    ]);

                    Log::warning('HLS_METRIC: broadcast_healed', ['network_id' => $network->id, 'uuid' => $network->uuid, 'old_pid' => $old]);
                    $this->info("Healed network {$network->id}");
                }
            }
        }

        return 0;
    }
}
