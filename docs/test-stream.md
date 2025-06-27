# Test Stream Controller Documentation

The `StreamTestController` provides endpoints for testing continuous streams with configurable timeouts. This is useful for testing stream stability and behavior when streams fail mid-stream.

## Endpoints

### Direct Stream
```
GET /api/stream/test/{timeout}.ts
```

### HLS Playlist
```
GET /api/stream/test/{timeout}.m3u8
```

## Parameters

- `{timeout}` - Integer representing seconds:
  - `0` = No timeout (stream runs indefinitely)
  - `10` = Stream runs for 10 seconds then terminates
  - `30` = Stream runs for 30 seconds then terminates
  - etc.

## Examples

### Infinite test stream
```
http://your-domain.com/api/stream/test/0.ts
http://your-domain.com/api/stream/test/0.m3u8
```

### 30-second test stream
```
http://your-domain.com/api/stream/test/30.ts
http://your-domain.com/api/stream/test/30.m3u8
```

## Stream Content

- **No timeout (0)**: Shows runtime counter counting up (00:00, 00:01, 00:02, etc.)
- **With timeout**: Shows countdown timer counting down to zero
- **Resolution**: 720p (1280x720) when FFmpeg is available, fallback to basic TS packets otherwise
- **Format**: MPEG-TS (.ts) format suitable for streaming players

## Features

- **FFmpeg Integration**: Automatically detects and uses FFmpeg for proper video generation with timer overlay
- **Fallback Mode**: If FFmpeg is not available, generates basic TS packets with timer data
- **Connection Monitoring**: Automatically stops when client disconnects
- **Logging**: Comprehensive logging for debugging stream behavior
- **CORS Support**: Includes CORS headers for web player compatibility

## Testing with Video Players

### VLC
1. Open VLC
2. Media → Open Network Stream
3. Enter: `http://your-domain.com/api/stream/test/30.m3u8`
4. Click Play

### FFplay
```bash
ffplay http://your-domain.com/api/stream/test/30.m3u8
```

### Web Players
The `.m3u8` playlist format is compatible with most web-based HLS players.

## Use Cases

1. **Stream Stability Testing**: Test how your application handles streams that suddenly terminate
2. **Timeout Testing**: Verify stream timeout handling with different durations
3. **Player Compatibility**: Test different video players with a known-good stream
4. **Network Testing**: Use as a test stream for network performance analysis
5. **Development**: Quick test stream without needing external sources

## Logs

All stream activity is logged to Laravel's standard logging system with the following information:
- Stream start/stop times
- Connection status
- Timeout events
- Error conditions
- Segment counts (in fallback mode)
