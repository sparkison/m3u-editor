<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RestartM3uProxy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u-proxy:restart';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart the embedded m3u-proxy service via supervisor';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $usingExternalProxy = config('proxy.external_proxy_enabled', false);

        if ($usingExternalProxy) {
            $this->warn('⚠️  Using external m3u-proxy service (M3U_PROXY_ENABLED=true).');
            $this->info('💡 This command restarts the embedded proxy. Restart your external service manually.');

            return self::FAILURE;
        }

        $this->info('🔄 Restarting m3u-proxy service...');

        // Use supervisorctl to restart the service
        $process = new Process(['supervisorctl', 'restart', 'm3u-proxy']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('❌ Failed to restart m3u-proxy:');
            $this->error($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->info('✅ m3u-proxy service restarted successfully!');
        $this->newLine();
        $this->info('📊 Check status with: supervisorctl status m3u-proxy');

        return self::SUCCESS;
    }
}
