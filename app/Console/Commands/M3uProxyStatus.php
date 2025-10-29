<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class M3uProxyStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u-proxy:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of the m3u-proxy service';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();

        $usingExternalProxy = config('proxy.external_proxy_enabled', false);
        $proxyUrl = rtrim(config('proxy.m3u_proxy_host'), '/') . ':' . config('proxy.m3u_proxy_port');

        $this->info('🔍 Checking m3u-proxy status...');
        $this->newLine();

        // Configuration info
        $mode = $usingExternalProxy ? 'External Service' : 'Embedded (in container)';
        $this->table(
            ['Setting', 'Value'],
            [
                ['Proxy Mode', $mode],
                ['M3U_PROXY_ENABLED', $usingExternalProxy ? 'true' : 'false/unset'],
                ['Proxy URL', $proxyUrl],
            ]
        );

        // Check supervisor status if using embedded
        if (! $usingExternalProxy) {
            $this->newLine();
            $this->info('📊 Supervisor Status:');
            $process = new Process(['supervisorctl', 'status', 'm3u-proxy']);
            $process->run();
            $this->line($process->getOutput());
        }

        // Check API health
        $this->newLine();
        $apiToken = config('proxy.m3u_proxy_token');
        $this->info('🏥 Health Check:');

        try {
            $response = Http::timeout(5)
                ->withHeaders($apiToken ? [
                    'X-API-Token' => $apiToken,
                ] : [])
                ->get($proxyUrl . '/health');

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✅ API is responding');
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Status', $data['status'] ?? 'unknown'],
                        ['Version', $data['version'] ?? 'unknown'],
                        ['Active Streams', $data['stats']['active_streams'] ?? 0],
                        ['Total Clients', $data['stats']['total_clients'] ?? 0],
                    ]
                );
            } else {
                $this->error('❌ API returned status: ' . $response->status());
            }
        } catch (\Exception $e) {
            $this->error('❌ Failed to connect to m3u-proxy API: ' . $e->getMessage());
            $this->newLine();

            if (! $usingExternalProxy) {
                $this->info('💡 Try restarting the embedded service: php artisan m3u-proxy:restart');
            } else {
                $this->info('💡 Check that the external m3u-proxy service is running.');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Display the header with ASCII art
     */
    private function displayHeader(): void
    {
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════╗');
        $this->line('║              M3U Proxy Status Monitor                 ║');
        $this->line('╚═══════════════════════════════════════════════════════╝');
    }
}
