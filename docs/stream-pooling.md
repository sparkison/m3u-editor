# Stream Pooling and Connection Management

## Overview

Stream pooling allows multiple clients to share a single transcoded stream from m3u-proxy without consuming additional provider connections. This is particularly important when:

1. **Provider has connection limits** (e.g., "max 1 connection" per account)
2. **Multiple users want to watch the same channel**
3. **Transcoding is enabled** (pooling works with transcoded streams)

## How It Works

### Without Pooling (Old Behavior)
```
User 1 → m3u-editor → m3u-proxy → Creates Stream A → Provider Connection 1
User 2 → m3u-editor → m3u-proxy → Creates Stream B → Provider Connection 2 ❌ (REJECTED if limit=1)
```

### With Pooling (New Behavior)
```
User 1 → m3u-editor → m3u-proxy → Creates Stream A → Provider Connection 1
User 2 → m3u-editor → m3u-proxy → Reuses Stream A → SAME Provider Connection ✅
User 3 → m3u-editor → m3u-proxy → Reuses Stream A → SAME Provider Connection ✅
```

## Implementation

### m3u-editor Side (M3uProxyService)

The `M3uProxyService` now checks for existing pooled streams before creating new ones:

```php
// In getChannelStream() method
if ($profile) {
    // Select provider profile if profiles are enabled
    if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
        $selectedProfile = ProfileService::selectProfile($playlist);
    }
    
    // Check for existing pooled stream (including provider profile)
    $existingStreamId = $this->findExistingPooledStream(
        $id, 
        $playlist->uuid, 
        $profile->id,
        $selectedProfile?->id  // Provider profile ID for accurate matching
    );
    
    if ($existingStreamId) {
        // Reuse existing stream
        return $this->buildTranscodeStreamUrl($existingStreamId);
    }
    
    // Create new stream if none exists
    $streamId = $this->createTranscodedStream(...);
}
```

### Finding Pooled Streams

The `findExistingPooledStream()` method queries m3u-proxy's `/streams/by-metadata` endpoint:

```php
protected function findExistingPooledStream(
    int $channelId, 
    string $playlistUuid, 
    ?int $profileId = null,
    ?int $providerProfileId = null
): ?string {
    // Query m3u-proxy for active streams with matching metadata
    $response = Http::get($endpoint, [
        'field' => 'id',
        'value' => (string) $channelId,
        'active_only' => true,
    ]);
    
    // Find stream matching all criteria:
    // 1. Channel ID
    // 2. Playlist UUID
    // 3. Transcoding enabled
    // 4. StreamProfile ID (transcoding profile)
    // 5. PlaylistProfile ID (provider profile) - NEW!
    foreach ($matchingStreams as $stream) {
        if (
            $metadata['id'] == $channelId &&
            $metadata['playlist_uuid'] === $playlistUuid &&
            $metadata['transcoding'] === 'true' &&
            ($profileId === null || $metadata['profile_id'] == $profileId) &&
            ($providerProfileId === null || $metadata['provider_profile_id'] == $providerProfileId)
        ) {
            return $stream['stream_id'];
        }
    }
}
```

### m3u-proxy Side

The m3u-proxy handles the actual stream pooling:

1. **Stream Creation**: Each transcoded stream includes metadata:
   ```json
   {
     "id": "456",
     "type": "channel",
     "playlist_uuid": "provider-b-uuid",
     "transcoding": "true",
     "profile_id": "5",
     "provider_profile_id": "2",
     "original_channel_id": "123",
     "original_playlist_uuid": "provider-a-uuid",
     "is_failover": true
   }
   ```
   
   Note: `id` and `playlist_uuid` represent the ACTUAL source, while `original_*` fields track the REQUESTED channel for cross-provider pooling.

2. **Client Registration**: Multiple clients can register to the same stream
3. **FFmpeg Process Sharing**: All clients receive data from the same FFmpeg transcoding process
4. **Grace Period Cleanup**: Stream stays alive for 10 seconds after last client disconnects

## Benefits

### Connection Efficiency
- **1 provider connection** serves unlimited clients (within reasonable limits)
- No more "max connections exceeded" errors for popular channels
- Reduced load on provider servers

### Resource Efficiency
- **1 FFmpeg process** transcodes for all clients on the same channel
- Lower CPU usage on the proxy server
- Reduced bandwidth from provider (single source stream)

### Better User Experience
- Users can watch the same channel simultaneously
- Instant playback for subsequent users (stream already running)
- No wait time for FFmpeg to start

## Connection Limit Behavior

### Playlist Connection Limits

m3u-editor still enforces playlist connection limits, but now checks **active m3u-proxy streams** instead of just database records:

```php
if ($playlist->available_streams !== 0) {
    $activeStreams = self::getCachedActiveStreamsCountByMetadata(
        'playlist_uuid', 
        $playlist->uuid, 
        1 // cache for 1 second
    );

    if ($activeStreams >= $playlist->available_streams) {
        abort(503, 'Playlist has reached its maximum stream limit.');
    }
}
```

**Key Point**: With pooling, 5 users watching the same channel only count as **1 active stream** towards the playlist limit!

### Provider Connection Limits

Provider limits are respected automatically:
- **Without pooling**: Each user = 1 provider connection (can exceed limit)
- **With pooling**: Multiple users = 1 provider connection (never exceeds limit)

## Requirements

### For Pooling to Work

1. **Transcoding must be enabled** (profile parameter provided)
2. **Same original channel** (same original channel ID - even if served from different failover sources)
3. **Same original playlist** (same original playlist UUID - even if served from different failover playlists)
4. **Same transcoding profile** (StreamProfile ID)
5. **Same provider profile** (PlaylistProfile ID, if using pooled provider profiles)
6. **Stream still active** (has at least one connected client)

**New in v1.x**: Pooling now works across cross-provider failovers! If Channel 123 from Provider A fails over to Channel 456 from Provider B, subsequent requests for Channel 123 will correctly pool into the existing stream.

### Direct Streams (Non-Transcoded)

Direct streams (without transcoding) **do NOT pool** because:
- Each client needs its own connection to the provider
- No shared FFmpeg process
- Provider may serve different bitrates/quality per connection

## API Endpoints Used

### Query for Pooled Streams
```
GET /streams/by-metadata?field=id&value=12345&active_only=true
```

Returns:
```json
{
  "filter": {"field": "id", "value": "12345"},
  "active_only": true,
  "matching_streams": [
    {
      "stream_id": "abc123...",
      "client_count": 3,
      "metadata": {
        "id": "12345",
        "type": "channel",
        "playlist_uuid": "...",
        "transcoding": "true"
      },
      "is_active": true,
      "url": "http://..."
    }
  ],
  "total_matching": 1,
  "total_clients": 3
}
```

### Get Stream Details
```
GET /streams/{stream_id}
```

Returns detailed info about a specific stream, including all connected clients.

## Logging

Look for these log messages:

### m3u-editor Logs
```
[INFO] Found existing pooled transcoded stream (cross-provider failover support)
  stream_id: abc123...
  original_channel_id: 123
  original_playlist_uuid: provider-a-uuid
  actual_channel_id: 456
  actual_playlist_uuid: provider-b-uuid
  is_failover: true
  profile_id: 5
  provider_profile_id: 2
  client_count: 3

[INFO] Reusing existing pooled transcoded stream (bypassing capacity check)
  stream_id: abc123...
  original_channel_id: 123
  original_playlist_uuid: provider-a-uuid
  profile_id: 5
  provider_profile_id: 2

[DEBUG] Creating transcoded stream with failover tracking
  original_channel_id: 123
  actual_channel_id: 456
  is_failover: true
  original_playlist_uuid: provider-a-uuid
  actual_playlist_uuid: provider-b-uuid
```

### m3u-proxy Logs
```
[INFO] Client client_xyz registered to transcoded stream abc123
[INFO] Transcoded stream abc123 now has 3 connected clients
[INFO] Last client disconnected from stream abc123, starting 10s grace period
[INFO] Grace period elapsed for stream abc123, stopping FFmpeg process
```

## Troubleshooting

### Users Can't Connect to Popular Channels

**Symptom**: Error "Playlist has reached its maximum stream limit"

**Cause**: Pooling isn't working - each user creates a new stream

**Check**:
1. Is transcoding enabled? (profile parameter set)
2. Are streams actually being created? (check m3u-proxy logs)
3. Is `findExistingPooledStream()` returning null? (check m3u-editor logs)

### Pooling Not Working

**Symptom**: Multiple FFmpeg processes for same channel

**Debug**:
```bash
# Check active streams via API
curl -H "X-API-Token: your-token" http://your-proxy:8085/m3u-proxy/streams

# Check for pooled streams
curl -H "X-API-Token: your-token" \
  "http://your-proxy:8085/m3u-proxy/streams/by-metadata?field=id&value=12345&active_only=true"
```

**Common Issues**:
- Different original channels (original_channel_id doesn't match)
- Different original playlists (original_playlist_uuid doesn't match)
- Different transcoding profiles (profile_id doesn't match)
- Different provider profiles (provider_profile_id doesn't match)
- Stream expired (no active clients when new user tries to connect)

### Stream Quality Issues

**Symptom**: Video stuttering or buffering with many clients

**Cause**: Single transcoded stream may not have enough bitrate for many clients

**Solutions**:
1. Increase transcoding bitrate in StreamProfile
2. Use hardware acceleration (NVENC/VAAPI)
3. Limit max concurrent clients per stream (not yet implemented)

## Future Enhancements

Potential improvements:

1. **Max clients per pool**: Limit how many clients can share one stream
2. **Quality tiers**: Different pools for different quality levels
3. **Load balancing**: Distribute clients across multiple transcoded streams
4. **Persistent streams**: Keep popular channels always transcoding
5. **Predictive pooling**: Pre-start streams for likely-to-be-watched channels

## Best Practices

1. **Enable transcoding for live channels** to benefit from pooling
2. **Set reasonable playlist limits** based on actual provider connection limits
3. **Monitor stream statistics** to understand usage patterns
4. **Use hardware acceleration** when available for better transcoding performance
5. **Cache stream queries** (already implemented with 1-second cache)
