<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use Illuminate\Console\Command;

class FixOrphanedPlaylists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-orphaned-playlists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore auto-sync for playlists whose parents were deleted';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = Playlist::whereNull('parent_id')
            ->where('auto_sync', false)
            ->update(['auto_sync' => true]);

        $this->info("Updated {$count} orphaned playlists.");

        return Command::SUCCESS;
    }
}
