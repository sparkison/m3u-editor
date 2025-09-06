<?php

namespace App\Console\Commands;

use App\Http\Controllers\LogoProxyController;
use Illuminate\Console\Command;

class LogoCacheCleanup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:logo-cleanup 
                                {--force : Force cleanup without confirmation}
                                {--all : Clear all, not just expired}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up cached logo files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This will delete expired logo cache files. Continue?')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $this->info('Cleaning up expired logo cache...');

        $all = $this->option('all') ?? false;

        $controller = new LogoProxyController();
        $clearedCount = $all
            ? $controller->clearCache()
            : $controller->clearExpiredCache();

        $this->info("Cleared {$clearedCount} logo cache files.");

        return Command::SUCCESS;
    }
}
