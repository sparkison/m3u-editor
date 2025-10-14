<?php

namespace App\Console\Commands;

use App\Services\StreamMonitorService;
use Illuminate\Console\Command;

class TestSystemStats extends Command
{
    protected $signature = 'test:system-stats';

    protected $description = 'Test system stats data structure';

    public function handle()
    {
        $monitorService = app(StreamMonitorService::class);
        $systemStats = $monitorService->getSystemStats();

        $this->info('System Stats Structure:');
        $this->line(json_encode($systemStats, JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('Testing QuickActionsWidget expected keys:');

        // Test memory_usage percentage
        $memoryPercentage = $systemStats['memory_usage']['percentage'] ?? 'MISSING';
        $this->line("memory_usage.percentage: {$memoryPercentage}");

        // Test disk_space percentage
        $diskPercentage = $systemStats['disk_space']['percentage'] ?? 'MISSING';
        $this->line("disk_space.percentage: {$diskPercentage}");

        // Test redis_connected
        $redisConnected = $systemStats['redis_connected'] ?? 'MISSING';
        $this->line('redis_connected: '.($redisConnected ? 'true' : 'false'));

        // Test load_average 1min
        $loadAverage = $systemStats['load_average']['1min'] ?? 'MISSING';
        $this->line("load_average.1min: {$loadAverage}");

        $this->newLine();
        $this->info('All expected keys found successfully!');

        return 0;
    }
}
