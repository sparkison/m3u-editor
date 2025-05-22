<?php

namespace App\Console\Commands;

use App\Services\DirectStreamManager;
use Illuminate\Console\Command;

class PruneDirectStreams extends Command
{
    protected $signature = 'app:direct-streams:prune';
    protected $description = 'Clean up inactive direct streaming processes';

    protected $streamManager;

    public function __construct(DirectStreamManager $streamManager)
    {
        parent::__construct();
        $this->streamManager = $streamManager;
    }

    public function handle()
    {
        $this->info('Cleaning up inactive direct streams...');
        $this->streamManager->cleanupInactiveStreams();
        $this->info('Done.');
    }
}
