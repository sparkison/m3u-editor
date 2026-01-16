<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Console\Command;

class PromoteTmpPlaylist extends Command
{
    protected $signature = 'network:promote-tmp-playlist {network}';

    protected $description = 'Promote a network live.m3u8.tmp to live.m3u8 when it is stable';

    public function handle(NetworkBroadcastService $service): int
    {
        $networkArg = $this->argument('network');

        try {
            $network = Network::where('uuid', $networkArg)->orWhere('id', $networkArg)->firstOrFail();
        } catch (\Throwable $e) {
            $this->error('Network not found');
            return 1;
        }

        $promoted = $service->promoteTmpPlaylistIfStable($network, 1);

        return $promoted ? 0 : 2;
    }
}
