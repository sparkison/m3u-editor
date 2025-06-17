<?php

namespace App\Http\Controllers\Api;

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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
            \Illuminate\Support\Facades\Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkDatabaseConnection(): bool
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
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
}
