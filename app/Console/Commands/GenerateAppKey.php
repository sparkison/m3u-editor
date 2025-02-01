<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class GenerateAppKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-key';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if app key currently exists
        if (config('app.key')) {
            $this->info("🔑 App key check confirmed\n");
            return;
        } else {
            $this->info("🔑 App key not found, generating one now...\n");
            Artisan::call('key:generate', [
                '--force' => true,
            ]);
        }
    }
}
