<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use App\Services\StreamMonitorService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Redis;

class StreamingAlertsWidget extends Widget
{
    protected static string $view = 'filament.widgets.streaming-alerts';
    protected static ?int $sort = 11;
    protected static ?string $pollingInterval = '10s';
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        return [
            'alerts' => $this->getActiveAlerts(),
            'systemHealth' => $this->getSystemHealthAlerts(),
            'streamIssues' => $this->getStreamIssues(),
            'performanceWarnings' => $this->getPerformanceWarnings(),
        ];
    }

    protected function getActiveAlerts(): array
    {
        $alerts = [];
        $monitorService = app(StreamMonitorService::class);
        
        // Check for unhealthy streams
        $unhealthyStreams = SharedStream::where('health_status', '!=', 'healthy')
                                      ->where('status', 'active')
                                      ->count();
        
        if ($unhealthyStreams > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Unhealthy Streams Detected',
                'message' => "{$unhealthyStreams} streams require attention",
                'icon' => 'heroicon-o-exclamation-triangle',
                'action' => 'View Shared Streams',
                'action_url' => '/admin/shared-stream-monitor',
                'timestamp' => now(),
            ];
        }

        // Check for high bandwidth usage
        $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');
        $bandwidthThreshold = config('proxy.shared_streaming.monitoring.bandwidth_threshold', 50000); // 50 Mbps
        
        if ($totalBandwidth > $bandwidthThreshold) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'High Bandwidth Usage',
                'message' => 'Total bandwidth: ' . round($totalBandwidth / 1000, 1) . ' Mbps exceeds threshold',
                'icon' => 'heroicon-o-signal',
                'action' => 'View Analytics',
                'action_url' => '/admin/streaming-dashboard',
                'timestamp' => now(),
            ];
        }

        // Check for Redis connectivity
        try {
            Redis::ping();
        } catch (\Exception $e) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Redis Connection Lost',
                'message' => 'Stream coordination may be affected',
                'icon' => 'heroicon-o-x-circle',
                'action' => 'Check System Health',
                'action_url' => '/admin/streaming-dashboard',
                'timestamp' => now(),
            ];
        }

        // Check for streams with no clients for extended periods
        $abandonedStreams = SharedStream::active()
                                      ->where('client_count', 0)
                                      ->where('started_at', '<', now()->subMinutes(30))
                                      ->count();
        
        if ($abandonedStreams > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Idle Streams Found',
                'message' => "{$abandonedStreams} streams running without clients for 30+ minutes",
                'icon' => 'heroicon-o-information-circle',
                'action' => 'Cleanup Streams',
                'action_url' => '/admin/shared-stream-monitor',
                'timestamp' => now(),
            ];
        }

        return $alerts;
    }

    protected function getSystemHealthAlerts(): array
    {
        $alerts = [];
        $monitorService = app(StreamMonitorService::class);
        $systemStats = $monitorService->getSystemStats();
        
        // Memory usage alert
        $memoryUsage = $systemStats['memory_usage']['percentage'] ?? 0;
        if ($memoryUsage > 90) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Critical Memory Usage',
                'message' => "Memory usage at {$memoryUsage}%",
                'severity' => 'high',
            ];
        } elseif ($memoryUsage > 80) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'High Memory Usage',
                'message' => "Memory usage at {$memoryUsage}%",
                'severity' => 'medium',
            ];
        }

        // Disk usage alert
        $diskUsage = $systemStats['disk_space']['percentage'] ?? 0;
        if ($diskUsage > 95) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Critical Disk Space',
                'message' => "Disk usage at {$diskUsage}%",
                'severity' => 'high',
            ];
        } elseif ($diskUsage > 85) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Low Disk Space',
                'message' => "Disk usage at {$diskUsage}%",
                'severity' => 'medium',
            ];
        }

        // CPU load alert
        $loadAverage = $systemStats['load_average']['1min'] ?? 0;
        $cpuCount = $systemStats['cpu_count'] ?? 1;
        $cpuUsage = ($loadAverage / $cpuCount) * 100;
        
        if ($cpuUsage > 95) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Critical CPU Load',
                'message' => "CPU load at {$cpuUsage}%",
                'severity' => 'high',
            ];
        } elseif ($cpuUsage > 80) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'High CPU Load',
                'message' => "CPU load at {$cpuUsage}%",
                'severity' => 'medium',
            ];
        }

        return $alerts;
    }

    protected function getStreamIssues(): array
    {
        $issues = [];
        
        // Note: Removed restart_count check as this column doesn't exist in shared_streams table
        // Could be implemented later with a proper restart tracking mechanism
        
        // Streams with zero bandwidth but have clients
        $stalledStreams = SharedStream::active()
                                    ->where('client_count', '>', 0)
                                    ->where('bandwidth_kbps', 0)
                                    ->get();
        
        foreach ($stalledStreams as $stream) {
            $issues[] = [
                'stream_id' => $stream->stream_id,
                'title' => 'Stream ' . substr($stream->stream_id, -8), // Use last 8 chars of stream_id as title
                'issue' => 'No data flow',
                'details' => "Stream has clients but no bandwidth usage",
                'severity' => 'medium',
            ];
        }

        // Streams with unhealthy status
        $unhealthyStreams = SharedStream::active()
                                      ->where('health_status', '!=', 'healthy')
                                      ->where('health_status', '!=', 'unknown')
                                      ->get();
        
        foreach ($unhealthyStreams as $stream) {
            $issues[] = [
                'stream_id' => $stream->stream_id,
                'title' => 'Stream ' . substr($stream->stream_id, -8),
                'issue' => 'Unhealthy status',
                'details' => "Stream health status: {$stream->health_status}",
                'severity' => 'medium',
            ];
        }

        return $issues;
    }

    protected function getPerformanceWarnings(): array
    {
        $warnings = [];
        
        // High client concentration on single stream
        $topStream = SharedStream::active()->orderByDesc('client_count')->first();
        if ($topStream && $topStream->client_count > 50) {
            $warnings[] = [
                'type' => 'performance',
                'title' => 'High Client Concentration',
                'message' => "Stream has {$topStream->client_count} clients - consider load balancing",
                'stream_id' => $topStream->stream_id,
            ];
        }

        // Buffer size warnings
        $largeBuffers = SharedStream::active()
                                  ->where('buffer_size', '>', 1073741824) // 1GB
                                  ->count();
        
        if ($largeBuffers > 0) {
            $warnings[] = [
                'type' => 'performance',
                'title' => 'Large Buffer Usage',
                'message' => "{$largeBuffers} streams using over 1GB buffer space",
            ];
        }

        return $warnings;
    }
}
