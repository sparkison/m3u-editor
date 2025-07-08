<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Redis;

/**
 * Shared Stream Client Model
 * 
 * Represents a client connection to a shared stream,
 * tracking individual client statistics and activity.
 */
class SharedStreamClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'stream_id',
        'client_id',
        'ip_address',
        'user_agent',
        'connected_at',
        'last_activity_at',
        'bytes_received',
        'status'
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'bytes_received' => 'integer'
    ];

    /**
     * Get the shared stream this client belongs to
     */
    public function sharedStream(): BelongsTo
    {
        return $this->belongsTo(SharedStream::class, 'stream_id', 'stream_id');
    }

    /**
     * Generate a unique client ID
     */
    public static function generateClientId(string $streamId, string $ipAddress): string
    {
        return $streamId . '_' . md5($ipAddress . microtime(true) . rand());
    }

    /**
     * Create a new client connection
     */
    public static function createConnection(
        string $streamId, 
        string $ipAddress, 
        ?string $userAgent = null
    ): self {
        $clientId = self::generateClientId($streamId, $ipAddress);
        
        return self::create([
            'stream_id' => $streamId,
            'client_id' => $clientId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'connected_at' => now(),
            'last_activity_at' => now(),
            'status' => 'connected'
        ]);
    }

    /**
     * Update client activity
     */
    public function updateActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
        
        // Update Redis cache
        Redis::hset("shared:client:{$this->client_id}", 'last_activity_at', now()->toISOString());
    }

    /**
     * Add bytes received
     */
    public function addBytesReceived(int $bytes): void
    {
        $this->increment('bytes_received', $bytes);
        
        // Update Redis cache
        Redis::hincrby("shared:client:{$this->client_id}", 'bytes_received', $bytes);
    }

    /**
     * Disconnect the client
     */
    public function disconnect(): void
    {
        $this->update(['status' => 'disconnected']);
        
        // Update stream client count
        $this->sharedStream?->updateClientCount();
        
        // Remove from Redis
        Redis::del("shared:client:{$this->client_id}");
    }

    /**
     * Check if client is considered active (within last 30 seconds)
     */
    public function isActive(): bool
    {
        return $this->status === 'connected' && 
               $this->last_activity_at >= now()->subSeconds(30);
    }

    /**
     * Get client duration in seconds
     */
    public function getDurationAttribute(): int
    {
        if (!$this->connected_at) {
            return 0;
        }

        $endTime = $this->status === 'disconnected' ? $this->updated_at : now();
        return $this->connected_at->diffInSeconds($endTime);
    }

    /**
     * Get bandwidth for this client in kbps
     */
    public function getBandwidthKbpsAttribute(): float
    {
        $duration = $this->duration;
        if ($duration === 0) {
            return 0;
        }

        return round(($this->bytes_received * 8) / ($duration * 1000), 2);
    }

    /**
     * Get client information as array
     */
    public function toClientArray(): array
    {
        return [
            'client_id' => $this->client_id,
            'stream_id' => $this->stream_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'status' => $this->status,
            'connected_at' => $this->connected_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'duration_seconds' => $this->duration,
            'bytes_received' => $this->bytes_received,
            'bandwidth_kbps' => $this->bandwidth_kbps,
            'is_active' => $this->isActive()
        ];
    }

    /**
     * Scope for active clients
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'connected')
                    ->where('last_activity_at', '>=', now()->subSeconds(30));
    }

    /**
     * Scope for inactive clients
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'connected')
                    ->where('last_activity_at', '<', now()->subSeconds(30));
    }

    /**
     * Clean up old disconnected clients
     */
    public static function cleanupOldClients(int $hoursOld = 24): int
    {
        return self::where('status', 'disconnected')
                  ->where('updated_at', '<', now()->subHours($hoursOld))
                  ->delete();
    }

    /**
     * Disconnect inactive clients
     */
    public static function disconnectInactiveClients(int $inactiveSeconds = 60): int
    {
        $inactiveClients = self::inactive()
                              ->where('last_activity_at', '<', now()->subSeconds($inactiveSeconds))
                              ->get();

        $count = 0;
        foreach ($inactiveClients as $client) {
            $client->disconnect();
            $count++;
        }

        return $count;
    }
}
