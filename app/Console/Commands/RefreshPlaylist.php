<?php

namespace App\Console\Commands;

use App\Enums\PlaylistStatus;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
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
            $playlist = Playlist::findOrFail($playlistId);
            dispatch(new ProcessM3uImport($playlist));
        } else {
            $this->info('Refreshing all playlists');
            $twentyFourHoursAgo = now()->subDay();
            $playlists = Playlist::query()->where([
                ['status', '!=', PlaylistStatus::Processing],
                ['synced', '<=', $twentyFourHoursAgo],
            ]);
            if ($playlists->count() === 0) {
                $this->info('No playlists ready refresh');
                return;
            }
            $playlists->get()->each(fn(Playlist $playlist) => dispatch(new ProcessM3uImport($playlist)));
            $this->info('Dispatched ' . $playlists->count() . ' playlists for refresh');
        }
        return;
    }
}
