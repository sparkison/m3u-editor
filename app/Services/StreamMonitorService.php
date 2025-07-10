<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\SharedStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Stream Monitor Service - Provides real-time stream monitoring like xTeVe
 * 
 * This service monitors active streams, tracks client connections,
 * and provides detailed statistics similar to xTeVe's monitoring interface.
 */
class StreamMonitorService
{
    public function __construct(
        public SharedStreamService $sharedStreamService
    ) {}

    /**
     * Get comprehensive streaming statistics
     */
    public function getStreamingStats(): array
    {
        return [
            'shared_streams' => $this->getSharedStreamStats(),
            'direct_streams' => $this->getDirectStreamStats(),
            'hls_streams' => $this->getHLSStreamStats(),
            'playlist_stats' => $this->getPlaylistStats(),
            'system_stats' => $this->getSystemStats()
        ];
    }

    /**
     * Get shared stream statistics (xTeVe-like streams) from the database
     */
    private function getSharedStreamStats(): array
    {
        // Fetch all shared streams from the database
        $streams = \App\Models\SharedStream::with('clients')->get();
        $result = [];
        $totalClients = 0;

        foreach ($streams as $stream) {
            $clients = $stream->clients;
            $clientCount = $clients->count();
            $totalClients += $clientCount;

            $result[] = [
                'id' => $stream->id,
                'stream_key' => $stream->stream_key ?? $stream->id,
                'channel_id' => $stream->channel_id ?? null,
                'episode_id' => $stream->episode_id ?? null,
                'status' => $stream->status ?? 'unknown',
                'health_status' => $stream->health_status ?? 'unknown',
                'client_count' => $clientCount,
                'clients' => $clients->map(function ($client) {
                    return [
                        'id' => $client->id,
                        'ip_address' => $client->ip_address ?? null,
                        'user_agent' => $client->user_agent ?? null,
                        'connected_at' => $client->created_at ?? null,
                        'last_activity' => $client->updated_at ?? null,
                        'bytes_received' => $client->bytes_received ?? null,
                        'is_active' => method_exists($client, 'isActive') ? $client->isActive() : null,
                        'duration' => $client->duration ?? null,
                    ];
                }),
                'bandwidth' => $stream->bandwidth ?? null,
                'created_at' => $stream->created_at,
                'updated_at' => $stream->updated_at,
            ];
        }

        return [
            'streams' => $result,
            'total_streams' => $streams->count(),
            'total_clients' => $totalClients,
        ];
    }

    /**
     * Get direct stream statistics (MPTS streams)
     */
    private function getDirectStreamStats(): array
    {
        $activeIds = Redis::smembers('mpts:active_ids');
        $streams = [];

        foreach ($activeIds as $clientDetails) {
            $parts = explode('::', $clientDetails);
            if (count($parts) >= 4) {
                [$ip, $modelId, $type, $streamId] = $parts;

                // Get stream details from cache
                $detailsKey = "mpts:streaminfo:details:{$streamId}";
                $details = Redis::get($detailsKey);
                $streamDetails = $details ? json_decode($details, true) : null;

                // Get start time
                $startTimeKey = "mpts:streaminfo:starttime:{$streamId}";
                $startTime = Redis::get($startTimeKey);

                // Get model information
                $model = null;
                if ($type === 'channel') {
                    $model = Channel::find($modelId);
                } elseif ($type === 'episode') {
                    $model = Episode::find($modelId);
                }

                $streams[] = [
                    'stream_id' => $streamId,
                    'type' => $type,
                    'model_id' => $modelId,
                    'title' => $model ? ($model->title_custom ?? $model->title ?? $model->name) : "Unknown {$type}",
                    'client_ip' => $ip,
                    'started_at' => $startTime,
                    'uptime' => $startTime ? (now()->timestamp - $startTime) : 0,
                    'format' => 'mpts',
                    'stream_details' => $streamDetails
                ];
            }
        }

        return [
            'total_streams' => count($streams),
            'streams' => $streams
        ];
    }

    /**
     * Get HLS stream statistics
     */
    private function getHLSStreamStats(): array
    {
        $channelIds = Redis::smembers('hls:active_channel_ids');
        $episodeIds = Redis::smembers('hls:active_episode_ids');
        $streams = [];

        // Process channel streams
        foreach ($channelIds as $channelId) {
            $lastSeen = Redis::get("hls:channel_last_seen:{$channelId}");
            $pidKey = "hls:pid:channel:{$channelId}";
            $pid = Cache::get($pidKey);

            $channel = Channel::find($channelId);
            if ($channel) {
                $streams[] = [
                    'type' => 'channel',
                    'model_id' => $channelId,
                    'title' => $channel->title_custom ?? $channel->title,
                    'format' => 'hls',
                    'pid' => $pid,
                    'last_seen' => $lastSeen,
                    'uptime' => $lastSeen ? (now()->timestamp - $lastSeen) : 0,
                    'status' => $this->checkProcessStatus($pid)
                ];
            }
        }

        // Process episode streams
        foreach ($episodeIds as $episodeId) {
            $lastSeen = Redis::get("hls:episode_last_seen:{$episodeId}");
            $pidKey = "hls:pid:episode:{$episodeId}";
            $pid = Cache::get($pidKey);

            $episode = Episode::find($episodeId);
            if ($episode) {
                $streams[] = [
                    'type' => 'episode',
                    'model_id' => $episodeId,
                    'title' => $episode->title,
                    'format' => 'hls',
                    'pid' => $pid,
                    'last_seen' => $lastSeen,
                    'uptime' => $lastSeen ? (now()->timestamp - $lastSeen) : 0,
                    'status' => $this->checkProcessStatus($pid)
                ];
            }
        }

        return [
            'total_streams' => count($streams),
            'streams' => $streams
        ];
    }

    /**
     * Get playlist streaming statistics
     */
    private function getPlaylistStats(): array
    {
        $playlists = Playlist::all();
        $stats = [];

        foreach ($playlists as $playlist) {
            $activeStreamsKey = "active_streams:{$playlist->id}";
            $activeCount = (int) Redis::get($activeStreamsKey) ?? 0;
            $maxStreams = $playlist->available_streams;

            $stats[] = [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'active_streams' => $activeCount,
                'max_streams' => $maxStreams,
                'utilization' => $maxStreams > 0 ? ($activeCount / $maxStreams * 100) : 0,
                'proxy_enabled' => $playlist->enable_proxy,
                'tuner_count' => $playlist->streams ?? 1
            ];
        }

        return $stats;
    }

    /**
     * Get system performance statistics
     */
    public function getSystemStats(): array
    {
        // System load
        $loadAvg = sys_getloadavg();

        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        // Get system memory info
        $memoryInfo = $this->getSystemMemoryInfo();

        // Get disk space info
        $diskInfo = $this->getDiskSpaceInfo();

        // Redis info
        $redisInfo = Redis::info('memory');
        $redisMemory = $redisInfo['used_memory_human'] ?? 'N/A';

        // Process count
        $totalProcesses = $this->getActiveProcessCount();

        // CPU count
        $cpuCount = $this->getCpuCount();

        return [
            'load_average' => [
                '1min' => $loadAvg[0],
                '5min' => $loadAvg[1],
                '15min' => $loadAvg[2]
            ],
            'cpu_count' => $cpuCount,
            'memory_usage' => $memoryInfo,
            'disk_space' => $diskInfo,
            'redis_connected' => $this->checkRedisConnection(),
            'memory' => [
                'current_usage' => $this->formatBytes($memoryUsage),
                'peak_usage' => $this->formatBytes($memoryPeak),
                'redis_usage' => $redisMemory
            ],
            'processes' => [
                'total_streaming' => $totalProcesses,
                'ffmpeg_processes' => $this->getFFmpegProcessCount()
            ],
            'uptime' => $this->getSystemUptime()
        ];
    }

    /**
     * Check if a process is still running
     */
    private function checkProcessStatus(?int $pid): string
    {
        if (!$pid) {
            return 'unknown';
        }

        if (posix_kill($pid, 0)) {
            return 'running';
        }

        return 'stopped';
    }

    /**
     * Get count of active streaming processes
     */
    private function getActiveProcessCount(): int
    {
        $sharedStreams = Redis::keys('stream_pid:*');
        $hlsChannels = Redis::smembers('hls:active_channel_ids');
        $hlsEpisodes = Redis::smembers('hls:active_episode_ids');
        $mptsStreams = Redis::smembers('mpts:active_ids');

        return count($sharedStreams) + count($hlsChannels) + count($hlsEpisodes) + count($mptsStreams);
    }

    /**
     * Get count of FFmpeg processes
     */
    private function getFFmpegProcessCount(): int
    {
        try {
            // Use cross-platform approach that works on both Linux and macOS
            $output = shell_exec('ps aux | grep -E "(ffmpeg|jellyfin-ffmpeg)" | grep -v grep | wc -l 2>/dev/null || echo 0');
            return (int) trim($output);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get system uptime
     */
    private function getSystemUptime(): ?string
    {
        try {
            $uptime = file_get_contents('/proc/uptime');
            if ($uptime) {
                $seconds = (float) explode(' ', $uptime)[0];
                return $this->formatDuration($seconds);
            }
        } catch (\Exception $e) {
            // Fallback for non-Linux systems
        }

        return null;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format duration to human readable format
     */
    private function formatDuration(float $seconds): string
    {
        $units = [
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
            'second' => 1
        ];

        $result = [];
        foreach ($units as $name => $div) {
            $quot = intval($seconds / $div);
            if ($quot) {
                $result[] = $quot . ' ' . $name . ($quot > 1 ? 's' : '');
                $seconds %= $div;
            }
        }

        return implode(', ', $result) ?: '0 seconds';
    }

    /**
     * Get bandwidth statistics for streams
     */
    public function getBandwidthStats(): array
    {
        $stats = [];

        // Get shared stream bandwidth (estimated)
        $sharedStreams = $this->getSharedStreamStats();
        foreach ($sharedStreams['streams'] as $stream) {
            $estimatedBandwidth = $this->estimateStreamBandwidth($stream);
            $stats[] = [
                'stream_key' => $stream['stream_key'],
                'title' => $stream['title'],
                'type' => 'shared',
                'format' => $stream['format'],
                'client_count' => $stream['client_count'],
                'estimated_bandwidth_mbps' => $estimatedBandwidth,
                'total_bandwidth_mbps' => $estimatedBandwidth * $stream['client_count']
            ];
        }

        return $stats;
    }

    /**
     * Estimate bandwidth for a stream (simplified)
     */
    private function estimateStreamBandwidth(array $stream): float
    {
        // Default estimates based on format
        $estimates = [
            'hls' => 3.0, // ~3 Mbps for HLS
            'ts' => 8.0,  // ~8 Mbps for direct TS
            'mpts' => 8.0 // ~8 Mbps for MPTS
        ];

        return $estimates[$stream['format']] ?? 5.0;
    }

    /**
     * Get stream health information
     */
    public function getStreamHealth(): array
    {
        $health = [
            'overall_status' => 'healthy',
            'issues' => [],
            'warnings' => []
        ];

        // Check for stuck streams
        $stuckStreams = $this->findStuckStreams();
        if (!empty($stuckStreams)) {
            $health['warnings'][] = 'Found ' . count($stuckStreams) . ' potentially stuck streams';
            $health['overall_status'] = 'warning';
        }

        // Check for high memory usage
        $memoryUsage = memory_get_usage(true);
        if ($memoryUsage > (512 * 1024 * 1024)) { // 512MB
            $health['warnings'][] = 'High memory usage: ' . $this->formatBytes($memoryUsage);
            $health['overall_status'] = 'warning';
        }

        // Check for high load
        $loadAvg = sys_getloadavg();
        if ($loadAvg[0] > 4.0) {
            $health['issues'][] = 'High system load: ' . round($loadAvg[0], 2);
            $health['overall_status'] = 'critical';
        }

        return $health;
    }

    /**
     * Find streams that appear to be stuck
     */
    private function findStuckStreams(): array
    {
        $stuck = [];
        $threshold = 300; // 5 minutes

        // Check shared streams
        $keys = Redis::keys('shared_stream:*');
        foreach ($keys as $key) {
            $streamData = json_decode(Redis::get($key), true);
            if ($streamData && isset($streamData['last_activity'])) {
                $lastActivity = $streamData['last_activity'];
                if ((now()->timestamp - $lastActivity) > $threshold) {
                    $stuck[] = [
                        'type' => 'shared',
                        'key' => str_replace('shared_stream:', '', $key),
                        'title' => $streamData['title'],
                        'last_activity' => $lastActivity,
                        'stale_duration' => now()->timestamp - $lastActivity
                    ];
                }
            }
        }

        return $stuck;
    }

    /**
     * Cleanup stale monitoring data
     */
    public function cleanupStaleData(): void
    {
        // This would be called by a scheduled job
        $stuckStreams = $this->findStuckStreams();

        foreach ($stuckStreams as $stream) {
            if ($stream['stale_duration'] > 600) { // 10 minutes
                $this->forceCleanupStream($stream);
                Log::channel('ffmpeg')->warning("Force cleaned up stale stream: {$stream['title']}");
            }
        }
    }

    /**
     * Force cleanup a stale stream
     */
    private function forceCleanupStream(array $streamInfo): void
    {
        if ($streamInfo['type'] === 'shared') {
            $streamKey = $streamInfo['key'];

            // Clean up Redis keys
            Redis::del("shared_stream:{$streamKey}");
            Redis::del("stream_clients:{$streamKey}");
            Redis::del("stream_pid:{$streamKey}");

            // Clean up buffer
            $bufferKey = "stream_buffer:{$streamKey}";
            $segments = Redis::lrange("{$bufferKey}:segments", 0, -1);
            foreach ($segments as $segmentNum) {
                Redis::del("{$bufferKey}:segment_{$segmentNum}");
            }
            Redis::del("{$bufferKey}:segments");
        }
    }

    /**
     * Update system statistics
     */
    public function updateSystemStats(): array
    {
        try {
            $loadAvg = sys_getloadavg();
            $stats = [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'load_average_1min' => $loadAvg[0],
                'load_average_5min' => $loadAvg[1],
                'load_average_15min' => $loadAvg[2],
                'uptime' => time() - $this->getStartTime(),
                'updated_at' => time()
            ];

            Redis::hMSet($this->getSystemStatsKey(), $stats);

            return $stats;
        } catch (\Exception $e) {
            Log::error("Failed to update system stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get stream statistics for all active streams
     */
    public function getStreamStats(): array
    {
        try {
            $streamKeys = Redis::keys('stream:*');
            $stats = [];

            foreach ($streamKeys as $key) {
                if (strpos($key, ':stats') === false) {
                    continue; // Only process stats keys
                }

                $streamKey = str_replace(['stream:', ':stats'], '', $key);
                $streamStats = Redis::hGetAll($key);

                if (!empty($streamStats)) {
                    $stats[$streamKey] = $streamStats;
                }
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error("Failed to get stream stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check health of a specific stream
     */
    public function checkStreamHealth(string $streamKey): array
    {
        try {
            $stream = SharedStream::where('stream_id', $streamKey)->first();
            $health = [
                'status' => 'unknown',
                'process_running' => false,
                'last_activity' => 0,
                'client_count' => 0,
                'uptime' => 0,
                'buffer_health' => 'unknown'
            ];

            if (!empty($stream)) {
                $health['last_activity'] = $stream->last_client_activity ? $stream->last_client_activity->getTimestamp() : 0;
                $health['uptime'] = $stream->started_at ? time() - $stream->started_at->getTimestamp() : 0;

                // Check if process is running
                $health['process_running'] = $stream->isProcessRunning();

                // Get client count
                $clients = $this->sharedStreamService->getClients($streamKey);
                $health['client_count'] = count($clients);

                // Determine overall status
                $now = time();
                $timeout = config('proxy.shared_streaming.monitoring.stream_timeout', 300);

                if ($health['process_running'] && ($now - $health['last_activity']) < $timeout) {
                    $health['status'] = 'healthy';
                } else {
                    $health['status'] = 'unhealthy';
                }

                // Check buffer health
                $health['buffer_health'] = $this->sharedStreamService->getStreamBufferDiskUsage($streamKey) === 0
                    ? 'empty'
                    : 'healthy';
            }

            // Add healthy boolean for backward compatibility
            $health['healthy'] = $health['status'] === 'healthy';

            // Determine if this is a critical failure worthy of automatic restart
            $health['critical'] = false;
            if (!$health['healthy']) {
                // Critical failures: process died and has been inactive for more than 5 minutes
                $inactiveTime = time() - $health['last_activity'];
                $health['critical'] = !$health['process_running'] && $inactiveTime > 300;
            }

            // Add reason for unhealthy streams
            if (!$health['healthy']) {
                $reasons = [];
                if (!$health['process_running']) {
                    $reasons[] = 'process not running';
                }
                if ((time() - $health['last_activity']) >= config('proxy.shared_streaming.monitoring.stream_timeout', 300)) {
                    $reasons[] = 'inactive';
                }
                if ($health['buffer_health'] === 'missing') {
                    $reasons[] = 'buffer missing';
                } else if ($health['buffer_health'] === 'empty') {
                    $reasons[] = 'buffer empty';
                }
                $health['reason'] = implode(', ', $reasons);
            }

            return $health;
        } catch (\Exception $e) {
            Log::error("Failed to check stream health: " . $e->getMessage());
            return [
                'status' => 'error',
                'healthy' => false,
                'critical' => false,
                'error' => $e->getMessage(),
                'reason' => 'health check failed'
            ];
        }
    }

    /**
     * Update global statistics
     */
    public function updateGlobalStats(array $stats): void
    {
        try {
            $globalKey = 'shared_streaming:global_stats';
            $current = Redis::hGetAll($globalKey) ?: [];

            // Ensure all values are strings or scalar for Redis hMSet
            $processedStats = [];
            foreach ($stats as $key => $value) {
                if (is_array($value)) {
                    // Convert arrays to JSON strings
                    $processedStats[$key] = json_encode($value);
                } elseif (is_bool($value)) {
                    // Convert booleans to strings
                    $processedStats[$key] = $value ? '1' : '0';
                } else {
                    // Keep scalars as is
                    $processedStats[$key] = (string) $value;
                }
            }

            // Merge with current stats
            $merged = array_merge($current, $processedStats);
            $merged['last_updated'] = (string) time();

            Redis::hMSet($globalKey, $merged);
        } catch (\Exception $e) {
            Log::error("Failed to update global stats: " . $e->getMessage());
        }
    }

    /**
     * Helper method to check if process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        try {
            return posix_kill($pid, 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get system stats Redis key
     */
    private function getSystemStatsKey(): string
    {
        return 'shared_streaming:system_stats';
    }

    /**
     * Get service start time
     */
    private function getStartTime(): int
    {
        $key = 'shared_streaming:start_time';
        $startTime = Redis::get($key);

        if (!$startTime) {
            $startTime = time();
            Redis::set($key, $startTime);
        }

        return (int)$startTime;
    }

    /**
     * Get system memory information
     */
    private function getSystemMemoryInfo(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!$meminfo) {
            return ['total' => 'N/A', 'free' => 'N/A', 'used' => 'N/A', 'percentage' => 0];
        }

        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

        $totalMem = isset($total[1]) ? (int)$total[1] * 1024 : 0;
        $availableMem = isset($available[1]) ? (int)$available[1] * 1024 : 0;
        $usedMem = $totalMem - $availableMem;

        return [
            'total' => $this->formatBytes($totalMem),
            'free' => $this->formatBytes($availableMem),
            'used' => $this->formatBytes($usedMem),
            'percentage' => $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 1) : 0,
        ];
    }

    /**
     * Get disk space information
     */
    private function getDiskSpaceInfo(): array
    {
        // Only return disk info for general storage, not for shared stream buffer
        $root = base_path();
        return [
            'total' => $this->formatBytes(disk_total_space($root)),
            'free' => $this->formatBytes(disk_free_space($root)),
            'used' => $this->formatBytes(disk_total_space($root) - disk_free_space($root)),
            'mount' => $root
        ];
    }

    /**
     * Check Redis connection
     */
    private function checkRedisConnection(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get number of CPU cores
     */
    private function getCpuCount(): int
    {
        try {
            // Try different approaches to get CPU count

            // Method 1: Use nproc if available (Linux/Unix)
            $output = shell_exec('nproc 2>/dev/null');
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }

            // Method 2: Parse /proc/cpuinfo (Linux)
            if (is_readable('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                if ($cpuinfo !== false) {
                    $processors = substr_count($cpuinfo, 'processor');
                    if ($processors > 0) {
                        return $processors;
                    }
                }
            }

            // Method 3: Use sysctl (macOS/BSD)
            $output = shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }

            // Method 4: Use wmic (Windows - unlikely in this context but comprehensive)
            $output = shell_exec('wmic cpu get NumberOfCores /value 2>/dev/null | find "NumberOfCores"');
            if ($output !== null) {
                preg_match('/NumberOfCores=(\d+)/', $output, $matches);
                if (isset($matches[1]) && is_numeric($matches[1])) {
                    return (int) $matches[1];
                }
            }

            // Fallback: return 1 if we can't determine
            return 1;
        } catch (\Exception $e) {
            return 1;
        }
    }
}
