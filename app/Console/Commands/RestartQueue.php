<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RestartQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:restart-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart Horizon queue and clear out any pending queue items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("🔄 Restarting Horizon queue...\n");
        $this->call('horizon:terminate');
        $this->call('queue:flush');
        $this->info("✅ Horizon queue restarted\n");
    }
}
