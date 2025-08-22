<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Models\SharedStreamStat;
use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Http\Controllers\Controller;
use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * API Controller for Shared Streaming Management
 */
class SharedStreamApiController extends Controller
{
    protected $sharedStreamService;
    protected $monitorService;

    public function __construct(
        SharedStreamService $sharedStreamService,
        StreamMonitorService $monitorService
    ) {
        $this->sharedStreamService = $sharedStreamService;
        $this->monitorService = $monitorService;
    }

    /**
     * Get streaming statistics
     */
    public function getStats(): JsonResponse
    {
        $stats = $this->monitorService->getStreamingStats();
        
        return response()->json([
            'success' => true,
            'data' => $stats,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Test stream creation
     */
    public function testStream(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'format' => 'required|in:ts,hls'
        ]);

        try {
            $result = $this->sharedStreamService->joinStream(
                $request->input('url'),
                $request->input('format'),
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Stream created/joined successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active streams
     */
    public function getActiveStreams(): JsonResponse
    {
        $streams = $this->monitorService->getActiveStreams();
        
        return response()->json([
            'success' => true,
            'data' => $streams,
            'count' => count($streams)
        ]);
    }

    /**
     * Stop a stream
     */
    public function stopStream(Request $request, string $streamId): JsonResponse
    {
        try {
            $success = $this->sharedStreamService->stopStream($streamId);
            
            return response()->json([
                'success' => $success,
                'message' => $success ? 'Stream stopped successfully' : 'Failed to stop stream'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cleanup inactive streams
     */
    public function cleanup(): JsonResponse
    {
        try {
            $result = $this->sharedStreamService->cleanupInactiveStreams();
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Cleanup completed successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system health
     */
    public function getHealth(): JsonResponse
    {
        $health = [
            'status' => 'healthy',
            'redis_connected' => $this->checkRedisConnection(),
            'database_connected' => $this->checkDatabaseConnection(),
            'disk_space' => $this->getDiskSpace(),
            'memory_usage' => $this->getMemoryUsage(),
            'timestamp' => now()->toISOString()
        ];

        $allHealthy = $health['redis_connected'] && $health['database_connected'];
        $health['status'] = $allHealthy ? 'healthy' : 'degraded';

        return response()->json([
            'success' => true,
            'data' => $health
        ]);
    }

    private function checkRedisConnection(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getDiskSpace(): array
    {
        $path = storage_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        
        return [
            'total' => $total,
            'free' => $free,
            'used' => $total - $free,
            'percentage' => $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0
        ];
    }

    private function getMemoryUsage(): array
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        return [
            'limit' => $memoryLimit,
            'current' => $memoryUsage,
            'peak' => $memoryPeak,
            'percentage' => $this->parseMemoryLimit($memoryLimit) > 0 ? 
                round(($memoryUsage / $this->parseMemoryLimit($memoryLimit)) * 100, 1) : 0
        ];
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = strtolower($limit);
        $bytes = intval($limit);
        
        if (strpos($limit, 'g') !== false) {
            $bytes *= 1024 * 1024 * 1024;
        } elseif (strpos($limit, 'm') !== false) {
            $bytes *= 1024 * 1024;
        } elseif (strpos($limit, 'k') !== false) {
            $bytes *= 1024;
        }
        
        return $bytes;
    }

    /**
     * Get dashboard analytics data
     */
    public function getDashboardData(): JsonResponse
    {
        try {
            $stats = $this->monitorService->getStreamingStats();
            $systemStats = $this->monitorService->getSystemStats();
            
            // Get recent performance metrics
            $recentMetrics = SharedStreamStat::selectRaw('
                    AVG(client_count) as avg_clients,
                    AVG(bandwidth_kbps) as avg_bandwidth,
                    MAX(client_count) as peak_clients,
                    MAX(bandwidth_kbps) as peak_bandwidth
                ')
                ->where('recorded_at', '>=', now()->subHour())
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'streaming_stats' => $stats,
                    'system_stats' => $systemStats,
                    'recent_metrics' => $recentMetrics,
                    'alerts' => $this->getSystemAlerts(),
                ],
                'timestamp' => now()->toISOString()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time streaming metrics
     */
    public function getRealTimeMetrics(): JsonResponse
    {
        try {
            $activeStreams = SharedStream::active()->count();
            $totalClients = SharedStream::active()->sum('client_count');
            $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');
            
            // Get recent connections (last 5 minutes)
            $recentConnections = SharedStreamClient::where('connected_at', '>=', now()->subMinutes(5))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'active_streams' => $activeStreams,
                    'total_clients' => $totalClients,
                    'total_bandwidth_kbps' => $totalBandwidth,
                    'recent_connections' => $recentConnections,
                    'bandwidth_formatted' => $totalBandwidth > 1000 ? 
                        round($totalBandwidth / 1000, 1) . ' Mbps' : 
                        $totalBandwidth . ' kbps',
                ],
                'timestamp' => now()->toISOString()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get streaming alerts
     */
    public function getAlerts(): JsonResponse
    {
        try {
            $alerts = $this->getSystemAlerts();
            
            return response()->json([
                'success' => true,
                'data' => $alerts,
                'count' => count($alerts),
                'timestamp' => now()->toISOString()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance history
     */
    public function getPerformanceHistory(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'sometimes|in:1h,6h,24h,7d',
            'metric' => 'sometimes|in:clients,bandwidth,streams'
        ]);

        try {
            $period = $request->input('period', '24h');
            $metric = $request->input('metric', 'clients');
            
            $startTime = match($period) {
                '1h' => now()->subHour(),
                '6h' => now()->subHours(6),
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
                default => now()->subDay(),
            };

            $groupBy = match($period) {
                '1h', '6h' => 'EXTRACT(MINUTE FROM recorded_at)',
                '24h' => 'EXTRACT(HOUR FROM recorded_at)',
                '7d' => 'DATE(recorded_at)',
                default => 'EXTRACT(HOUR FROM recorded_at)',
            };

            $stats = SharedStreamStat::selectRaw("
                    {$groupBy} as period,
                    AVG(client_count) as avg_clients,
                    AVG(bandwidth_kbps) as avg_bandwidth,
                    COUNT(DISTINCT stream_id) as stream_count
                ")
                ->where('recorded_at', '>=', $startTime)
                ->groupBy('period')
                ->orderBy('period')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'period' => $period,
                'metric' => $metric,
                'timestamp' => now()->toISOString()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system alerts
     */
    private function getSystemAlerts(): array
    {
        $alerts = [];
        
        // Check for unhealthy streams
        $unhealthyStreams = SharedStream::where('health_status', '!=', 'healthy')
                                                  ->where('status', 'active')
                                                  ->count();
        
        if ($unhealthyStreams > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Unhealthy Streams',
                'message' => "{$unhealthyStreams} streams need attention",
                'severity' => 'medium',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Check Redis connectivity
        if (!$this->checkRedisConnection()) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Redis Connection Lost',
                'message' => 'Stream coordination may be affected',
                'severity' => 'high',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Check for high bandwidth usage
        $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');
        $threshold = config('proxy.shared_streaming.monitoring.bandwidth_threshold', 50000);
        
        if ($totalBandwidth > $threshold) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'High Bandwidth Usage',
                'message' => 'Total bandwidth exceeds threshold',
                'severity' => 'medium',
                'timestamp' => now()->toISOString(),
            ];
        }

        return $alerts;
    }
}
