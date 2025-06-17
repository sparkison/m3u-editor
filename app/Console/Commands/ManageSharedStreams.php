<?php

namespace App\Console\Commands;

use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ManageSharedStreams extends Command
{
    /**
     * The name and signature of the console         foreach ($streams as $streamKey => $streamData) {
            $health = $this->monitorService->checkStreamHealth($streamKey);
            $status = ($health['healthy'] ?? false) ? '<info>✓ Healthy</info>' : '<error>✗ Unhealthy</error>';
            $reason = $health['reason'] ?? '';
            
            $streamInfo = $streamData['stream_info'];
            $title = substr($streamInfo['title'] ?? 'Unknown', 0, 30);
            
            $this->line("{$title}: {$status}" . ($reason ? " ({$reason})" : ""));
            
            if (!($health['healthy'] ?? false)) {
                $unhealthyCount++;
            }
        }*/
    protected $signature = 'app:shared-streams {action} {--stream-key=} {--force}';

    /**
     * The console command description.
     */
    protected $description = 'Manage shared streams (list, stop, restart, cleanup, sync)';

    private SharedStreamService $sharedStreamService;
    private StreamMonitorService $monitorService;

    public function __construct(
        SharedStreamService $sharedStreamService,
        StreamMonitorService $monitorService
    ) {
        parent::__construct();
        $this->sharedStreamService = $sharedStreamService;
        $this->monitorService = $monitorService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');
        $streamKey = $this->option('stream-key');
        $force = $this->option('force');

        switch ($action) {
            case 'list':
                return $this->listStreams();
            
            case 'stop':
                if (!$streamKey) {
                    $this->error('--stream-key is required for stop action');
                    return 1;
                }
                return $this->stopStream($streamKey, $force);
            
            case 'stop-all':
                return $this->stopAllStreams($force);
            
            case 'restart':
                if (!$streamKey) {
                    $this->error('--stream-key is required for restart action');
                    return 1;
                }
                return $this->restartStream($streamKey);
            
            case 'cleanup':
                return $this->cleanupStreams($force);
            
            case 'sync':
                return $this->synchronizeState();
            
            case 'stats':
                return $this->showStats();
            
            case 'health':
                return $this->checkHealth();
            
            default:
                $this->error("Unknown action: {$action}");
                $this->line('Available actions: list, stop, stop-all, restart, cleanup, sync, stats, health');
                return 1;
        }
    }

    private function listStreams(): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        
        if (empty($streams)) {
            $this->info('No active shared streams found.');
            return 0;
        }

        $this->info('Active Shared Streams:');
        $this->newLine();

        $headers = ['Stream Key', 'Type', 'Title', 'Format', 'Clients', 'Uptime', 'Status'];
        $rows = [];

        foreach ($streams as $streamKey => $streamData) {
            $streamInfo = $streamData['stream_info'];
            $uptime = $this->formatUptime($streamData['uptime'] ?? 0);
            
            $rows[] = [
                substr($streamKey, 0, 16) . '...',
                $streamInfo['type'] ?? 'unknown',
                substr($streamInfo['title'] ?? 'Unknown', 0, 30),
                $streamInfo['format'] ?? 'unknown',
                $streamData['client_count'] ?? 0,
                $uptime,
                $streamInfo['status'] ?? 'unknown'
            ];
        }

        $this->table($headers, $rows);
        $this->info(sprintf('Total: %d active streams', count($streams)));
        
        return 0;
    }

    private function stopStream(string $streamKey, bool $force): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        if (!isset($streams[$streamKey])) {
            $this->error("Stream not found: {$streamKey}");
            return 1;
        }

        $streamData = $streams[$streamKey];
        $clientCount = $streamData['client_count'] ?? 0;

        if (!$force && $clientCount > 0) {
            $this->error("Stream has {$clientCount} connected clients. Use --force to stop anyway.");
            return 1;
        }

        $this->info("Stopping stream: {$streamKey}");
        
        // Stop the stream
        $success = $this->sharedStreamService->stopStream($streamKey);
        if ($success) {
            $this->info("Stream stopped successfully.");
            return 0;
        } else {
            $this->error("Failed to stop stream.");
            return 1;
        }
    }

    private function stopAllStreams(bool $force): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        
        if (empty($streams)) {
            $this->info('No active streams to stop.');
            return 0;
        }

        if (!$force && !$this->confirm("Stop all {count($streams)} active streams?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $stopped = 0;
        foreach ($streams as $streamKey => $streamData) {
            try {
                $success = $this->sharedStreamService->stopStream($streamKey);
                if ($success) {
                    $stopped++;
                }
            } catch (\Exception $e) {
                $this->error("Failed to stop stream {$streamKey}: " . $e->getMessage());
            }
        }

        $this->info("Stopped {$stopped} streams.");
        return 0;
    }

    private function restartStream(string $streamKey): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        if (!isset($streams[$streamKey])) {
            $this->error("Stream not found: {$streamKey}");
            return 1;
        }

        $this->info("Restarting stream: {$streamKey}");
        
        try {
            $success = $this->sharedStreamService->restartStream($streamKey);
            if ($success) {
                $this->info("Stream restarted successfully.");
                return 0;
            } else {
                $this->error("Failed to restart stream.");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Error restarting stream: " . $e->getMessage());
            return 1;
        }
    }

    private function cleanupStreams(bool $force): int
    {
        $this->info('Starting shared stream cleanup...');
        
        try {
            $activeStreams = $this->sharedStreamService->getAllActiveStreams();
            $cleanedUp = 0;
            
            foreach ($activeStreams as $streamKey => $streamData) {
                $clientCount = $streamData['client_count'];
                $lastActivity = $streamData['last_activity'] ?? time();
                
                // Clean up streams with no clients and inactive for more than 1 minute (or force cleanup)
                $isStale = $clientCount === 0 && ((time() - $lastActivity) > 60 || $force);
                
                if ($isStale) {
                    $this->line("Cleaning up stale stream: {$streamKey}");
                    $success = $this->sharedStreamService->cleanupStream($streamKey, true);
                    if ($success) {
                        $cleanedUp++;
                    }
                }
            }

            // Clean up orphaned keys and temp files
            $orphanedKeys = $this->sharedStreamService->cleanupOrphanedKeys();
            $tempFiles = $this->sharedStreamService->cleanupTempFiles();

            $this->info("Cleanup completed:");
            $this->line("- Cleaned up {$cleanedUp} streams");
            $this->line("- Removed {$orphanedKeys} orphaned keys");
            $this->line("- Cleaned {$tempFiles} bytes of temp files");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Cleanup failed: " . $e->getMessage());
            return 1;
        }
    }

    private function showStats(): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        $systemStats = $this->monitorService->getSystemStats();
        
        $totalClients = array_sum(array_column($streams, 'client_count'));
        $totalBandwidth = 0;
        
        // Calculate total bandwidth
        foreach ($streams as $streamData) {
            $health = $this->monitorService->checkStreamHealth($streamData['stream_info']['stream_key'] ?? '');
            if (isset($health['bandwidth'])) {
                $totalBandwidth += $health['bandwidth'];
            }
        }

        $this->info('Shared Streaming Statistics:');
        $this->newLine();
        
        $this->line("Total Active Streams: " . count($streams));
        $this->line("Total Connected Clients: {$totalClients}");
        $this->line("Total Bandwidth: " . $this->formatBytes($totalBandwidth) . "/s");
        $this->newLine();
        
        $this->line("System Stats:");
        $this->line("- CPU Usage: " . round($systemStats['cpu_usage'] ?? 0, 1) . "%");
        $this->line("- Memory Usage: " . round($systemStats['memory_usage']['percentage'] ?? 0, 1) . "%");
        $this->line("- Disk Usage: " . round($systemStats['disk_space']['percentage'] ?? 0, 1) . "%");
        $this->line("- Load Average: " . ($systemStats['load_average']['1min'] ?? 'N/A'));
        $this->line("- Active FFmpeg Processes: " . ($systemStats['processes']['ffmpeg_processes'] ?? 0));
        $this->line("- Redis Connected: " . ($systemStats['redis_connected'] ? 'Yes' : 'No'));
        
        return 0;
    }

    private function checkHealth(): int
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();
        $unhealthyCount = 0;
        
        $this->info('Checking stream health...');
        $this->newLine();
        
        foreach ($streams as $streamKey => $streamData) {
            $health = $this->monitorService->checkStreamHealth($streamKey);
            $status = $health['healthy'] ? '<info>✓ Healthy</info>' : '<error>✗ Unhealthy</error>';
            $reason = $health['reason'] ?? '';
            
            $streamInfo = $streamData['stream_info'];
            $title = substr($streamInfo['title'] ?? 'Unknown', 0, 30);
            
            $this->line("{$title}: {$status}" . ($reason ? " ({$reason})" : ""));
            
            if (!$health['healthy']) {
                $unhealthyCount++;
            }
        }
        
        $this->newLine();
        if ($unhealthyCount === 0) {
            $this->info("All streams are healthy!");
        } else {
            $this->warn("{$unhealthyCount} unhealthy streams detected.");
        }
        
        return $unhealthyCount > 0 ? 1 : 0;
    }

    private function synchronizeState(): int
    {
        $this->info('Synchronizing shared stream state between database and Redis...');
        
        try {
            $stats = $this->sharedStreamService->synchronizeState();
            
            $this->info('Synchronization completed:');
            $this->line("- Database records updated: {$stats['db_updated']}");
            $this->line("- Redis entries cleaned: {$stats['redis_cleaned']}");
            $this->line("- Inconsistencies fixed: {$stats['inconsistencies_fixed']}");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Synchronization failed: " . $e->getMessage());
            return 1;
        }
    }

    private function formatUptime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}
