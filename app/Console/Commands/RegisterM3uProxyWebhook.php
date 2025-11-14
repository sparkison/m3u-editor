<?php

namespace App\Console\Commands;

use App\Services\M3uProxyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterM3uProxyWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u-proxy:register-webhook 
                            {--force : Force re-registration even if webhook already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register m3u-editor webhook with m3u-proxy for real-time cache invalidation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔗 Registering m3u-editor webhook with m3u-proxy...');

        $service = new M3uProxyService();
        
        if (empty($service->apiBaseUrl)) {
            $this->error('❌ M3U Proxy API URL is not configured');
            return self::FAILURE;
        }

        if (empty($service->apiToken)) {
            $this->error('❌ M3U Proxy API token is not configured');
            return self::FAILURE;
        }

        // Construct webhook URL - use APP_URL instead of apiPublicUrl
        // because m3u-proxy needs to call back to Laravel, not to itself
        $appUrl = rtrim(config('app.url'), '/');
        $webhookUrl = $appUrl . '/api/m3u-proxy/webhooks';

        $this->info("Webhook URL: {$webhookUrl}");
        $this->info("M3U Proxy API: {$service->apiBaseUrl}");

        try {
            // Check if webhook already exists
            $listEndpoint = $service->apiBaseUrl . '/webhooks';
            $listResponse = Http::timeout(5)->acceptJson()
                ->withHeaders([
                    'X-API-Token' => $service->apiToken,
                ])
                ->get($listEndpoint);

            if ($listResponse->successful()) {
                $data = $listResponse->json();
                $webhooks = $data['webhooks'] ?? [];
                
                // Check if our webhook is already registered
                $alreadyRegistered = false;
                foreach ($webhooks as $webhook) {
                    if ($webhook['url'] === $webhookUrl) {
                        $alreadyRegistered = true;
                        break;
                    }
                }

                if ($alreadyRegistered && !$this->option('force')) {
                    $this->info('✅ Webhook already registered');
                    return self::SUCCESS;
                }

                if ($alreadyRegistered && $this->option('force')) {
                    $this->warn('⚠️  Webhook already registered, removing and re-registering...');
                    
                    // Remove existing webhook
                    $deleteEndpoint = $service->apiBaseUrl . '/webhooks';
                    $deleteResponse = Http::timeout(5)->acceptJson()
                        ->withHeaders([
                            'X-API-Token' => $service->apiToken,
                        ])
                        ->delete($deleteEndpoint, [
                            'webhook_url' => $webhookUrl,
                        ]);

                    if (!$deleteResponse->successful()) {
                        $this->warn('⚠️  Failed to remove existing webhook, continuing anyway...');
                    }
                }
            }

            // Register webhook
            $registerEndpoint = $service->apiBaseUrl . '/webhooks';
            $payload = [
                'url' => $webhookUrl,
                'events' => [
                    'CLIENT_CONNECTED',
                    'CLIENT_DISCONNECTED',
                    'STREAM_STARTED',
                    'STREAM_ENDED',
                ],
                'timeout' => 5,
                'retry_attempts' => 2,
            ];

            $this->info('Registering webhook with events: ' . implode(', ', $payload['events']));

            $response = Http::timeout(10)->acceptJson()
                ->withHeaders([
                    'X-API-Token' => $service->apiToken,
                ])
                ->post($registerEndpoint, $payload);

            if ($response->successful()) {
                $this->info('✅ Webhook registered successfully!');
                
                Log::info('M3U Proxy webhook registered', [
                    'webhook_url' => $webhookUrl,
                    'events' => $payload['events'],
                ]);

                return self::SUCCESS;
            }

            $this->error('❌ Failed to register webhook: ' . $response->body());
            return self::FAILURE;

        } catch (\Exception $e) {
            $this->error('❌ Error registering webhook: ' . $e->getMessage());
            Log::error('Failed to register m3u-proxy webhook', [
                'error' => $e->getMessage(),
                'webhook_url' => $webhookUrl,
            ]);
            return self::FAILURE;
        }
    }
}

