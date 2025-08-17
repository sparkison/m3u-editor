<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Shared Stream Model
 * 
 * Represents a shared stream that multiple clients can connect to,
 * implementing xTeVe-like functionality for efficient streaming.
 */
class SharedStream extends Model
{
    use HasFactory;

    protected $fillable = [
        'stream_id',
        'source_url',
        'format',
        'status',
        'error_message',
        'process_id',
        'buffer_path',
        'buffer_size',
        'client_count',
        'last_client_activity',
        'stream_info',
        'ffmpeg_options',
        'bytes_transferred',
        'bandwidth_kbps',
        'health_check_at',
        'health_status',
        'started_at',
        'stopped_at'
    ];

    protected $casts = [
        'stream_info' => 'array',
        'ffmpeg_options' => 'array',
        'last_client_activity' => 'datetime',
        'health_check_at' => 'datetime',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'bytes_transferred' => 'integer',
        'bandwidth_kbps' => 'integer',
        'buffer_size' => 'integer',
        'process_id' => 'integer',
        'client_count' => 'integer'
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'stream_id';
    }

    /**
     * Generate a unique stream ID
     */
    public static function generateStreamId(): string
    {
        do {
            $streamId = 'shared_' . Str::random(16);
        } while (self::where('stream_id', $streamId)->exists());

        return $streamId;
    }

    /**
     * Get all clients connected to this stream
     */
    public function clients(): HasMany
    {
        return $this->hasMany(SharedStreamClient::class, 'stream_id', 'stream_id');
    }

    /**
     * Get active clients only
     */
    public function activeClients(): HasMany
    {
        return $this->clients()->where('status', 'connected');
    }

    /**
     * Get statistics for this stream
     */
    public function stats(): HasMany
    {
        return $this->hasMany(SharedStreamStat::class, 'stream_id', 'stream_id')
            ->orderBy('recorded_at', 'desc');
    }

    /**
     * Get recent statistics
     */
    public function recentStats(int $minutes = 60): HasMany
    {
        return $this->stats()
            ->where('recorded_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Check if the stream is currently active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the stream process is running
     */
    public function isProcessRunning(): bool
    {
        if (!$this->process_id) {
            return false;
        }

        $pid = (int)$this->process_id;
        if (!function_exists('posix_getpgid')) {
            // Fallback for non-POSIX systems (e.g., Windows)
            try {
                // Use ps to check process status (works on both Linux and macOS)
                $output = shell_exec("ps -p {$pid} -o stat= 2>/dev/null");

                if (empty(trim($output))) {
                    // Process doesn't exist
                    return false;
                }

                $stat = trim($output);
                // Check for zombie or dead processes
                // Z = zombie, X = dead on most systems
                if (preg_match('/^[ZX]/', $stat)) {
                    Log::channel('ffmpeg')->debug("Process {$pid} exists but is in state '{$stat}' (zombie/dead)");
                    return false;
                }

                // Process exists and is not zombie/dead
                return true;
            } catch (\Exception $e) {
                Log::channel('ffmpeg')->error("Error checking if process {$pid} is running: " . $e->getMessage());
                return false;
            }
        }
        // Check if the process is running using posix_getpgid
        // this is a more reliable way to check if a process is running
        return posix_getpgid($pid) !== false;
    }

    /**
     * Update client count from database
     */
    public function updateClientCount(): void
    {
        $count = $this->activeClients()->count();
        $this->update(['client_count' => $count]);

        // Also update Redis cache
        Redis::hset("shared:stream:{$this->stream_id}", 'client_count', $count);
    }

    /**
     * Add bytes to the total transferred
     */
    public function addBytesTransferred(int $bytes): void
    {
        $this->increment('bytes_transferred', $bytes);

        // Update Redis cache
        Redis::hincrby("shared:stream:{$this->stream_id}", 'bytes_transferred', $bytes);
    }

    /**
     * Update bandwidth calculation
     */
    public function updateBandwidth(int $kbps): void
    {
        $this->update(['bandwidth_kbps' => $kbps]);

        // Update Redis cache
        Redis::hset("shared:stream:{$this->stream_id}", 'bandwidth_kbps', $kbps);
    }

    /**
     * Update health status
     */
    public function updateHealthStatus(string $status): void
    {
        $this->update([
            'health_status' => $status,
            'health_check_at' => now()
        ]);

        // Update Redis cache
        Redis::hmset("shared:stream:{$this->stream_id}", [
            'health_status' => $status,
            'health_check_at' => now()->toISOString()
        ]);
    }

    /**
     * Get stream information as array
     */
    public function toStreamArray(): array
    {
        return [
            'stream_id' => $this->stream_id,
            'source_url' => $this->source_url,
            'format' => $this->format,
            'status' => $this->status,
            'client_count' => $this->client_count,
            'bandwidth_kbps' => $this->bandwidth_kbps,
            'bytes_transferred' => $this->bytes_transferred,
            'buffer_size' => $this->buffer_size,
            'health_status' => $this->health_status,
            'started_at' => $this->started_at?->toISOString(),
            'last_client_activity' => $this->last_client_activity?->toISOString(),
            'uptime_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : 0
        ];
    }

    /**
     * Scope to get only active streams
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['starting', 'active']);
    }

    /**
     * Scope for streams with clients
     */
    public function scopeWithClients($query)
    {
        return $query->where('client_count', '>', 0);
    }

    /**
     * Clean up stopped streams older than specified time
     */
    public static function cleanupOldStreams(int $hoursOld = 24): int
    {
        return self::where('status', 'stopped')
            ->where('stopped_at', '<', now()->subHours($hoursOld))
            ->delete();
    }
}
