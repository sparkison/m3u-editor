<?php

namespace App\Console\Commands;

use App\Services\SchedulesDirectService;
use Illuminate\Console\Command;

class TestSchedulesDirectCommand extends Command
{
    protected $signature = 'app:schedules-direct-test {username} {password} {country=USA} {postal_code=60030}';
    protected $description = 'Test Schedules Direct API connection and list available lineups';

    public function handle(SchedulesDirectService $service): int
    {
        $username = $this->argument('username');
        $password = $this->argument('password');
        $country = $this->argument('country');
        $postalCode = $this->argument('postal_code');

        try {
            $this->info("Testing Schedules Direct API connection...");

            // Test authentication
            $this->info("Authenticating with username: {$username}");
            $authData = $service->authenticate($username, $password);
            $this->info("✓ Authentication successful");
            $this->info("Token expires at: {$authData['expires_at']}");

            $token = $authData['token'];

            // Get status
            $this->info("\nGetting server status...");
            $status = $service->getStatus($token);
            $this->info("✓ Server status: " . ($status['systemStatus'][0]['status'] ?? 'Unknown'));

            // Get available countries
            $this->info("\nGetting available countries...");
            $countries = $service->getCountries();
            $this->info("✓ Available countries loaded");

            // Get headends for postal code
            $this->info("\nGetting headends for {$country} {$postalCode}...");
            $headends = $service->getHeadends($token, $country, $postalCode);
            $this->info("✓ Found " . count($headends) . " headend(s)");

            // Display headends and lineups
            foreach ($headends as $headend) {
                $this->line("");
                $this->line("Headend: {$headend['headend']} ({$headend['transport']}) - {$headend['location']}");
                
                foreach ($headend['lineups'] as $lineup) {
                    $this->line("  • {$lineup['name']} (ID: {$lineup['lineup']})");
                    
                    // Preview first lineup as example
                    if ($lineup === $headend['lineups'][0]) {
                        try {
                            $preview = $service->previewLineup($token, $lineup['lineup']);
                            $this->line("    Preview: " . count($preview) . " channels");
                            
                            // Show first few channels
                            foreach (array_slice($preview, 0, 3) as $channel) {
                                $this->line("      - Ch {$channel['channel']}: {$channel['name']} ({$channel['callsign']})");
                            }
                            if (count($preview) > 3) {
                                $this->line("      ... and " . (count($preview) - 3) . " more channels");
                            }
                        } catch (\Exception $e) {
                            $this->line("    Preview failed: " . $e->getMessage());
                        }
                    }
                }
            }

            $this->info("\n✓ Schedules Direct API test completed successfully");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("✗ Test failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
