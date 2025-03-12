<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
    protected $description = 'Generate an app key if it does not exist.';

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
            $this->call('key:generate', [
                '--force' => true,
            ]);
        }
    }
}
