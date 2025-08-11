<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ClearEpgFileCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-playlist-epg-files 
                           {--force : Force cache clearing without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the EPG file cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('Are you sure you want to clear all EPG file caches?')) {
            $this->info('Cache clearing cancelled.');
            return 0;
        }

        $this->info('Clearing EPG file cache...');

        $disk = Storage::disk('local');
        $cacheDir = 'playlist-epg-files';
        $filesDeleted = 0;

        if ($disk->exists($cacheDir)) {
            $files = $disk->allFiles($cacheDir);
            foreach ($files as $file) {
                $disk->delete($file);
                $filesDeleted++;
            }

            // Remove the directory if it's empty
            if (empty($disk->allFiles($cacheDir))) {
                $disk->deleteDirectory($cacheDir);
            }
        }

        if ($filesDeleted > 0) {
            $this->info("Successfully cleared {$filesDeleted} cached EPG files.");
        } else {
            $this->info('No cached EPG files found to clear.');
        }

        return 0;
    }
}
