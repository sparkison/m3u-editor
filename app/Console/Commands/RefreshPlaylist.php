<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshPlaylist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-playlist {playlist?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh playlist in batch (or specific playlist when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $playlistId = $this->argument('playlist');

        if ($playlistId) {
            $this->info("Refreshing playlist with ID: {$playlistId}");
        } else {
            $this->info('Refreshing all playlists');
        }

        // @todo: Implement the logic to refresh the playlist(s)
        $this->error('Not implemented yet');
    }
}
