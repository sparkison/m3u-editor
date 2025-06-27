# Test Stream Documentation

The `StreamTestController` provides endpoints for testing continuous streams with configurable timeouts. This is useful for testing stream stability and behavior when streams fail mid-stream.

## Endpoints

### HLS Playlist (Recommended)
```
GET /api/stream/test/{timeout}.m3u8
```

### Individual Segments 
```
GET /api/stream/test/{timeout}/segment_{segment}.ts
```

### Direct Stream (Legacy)
```
GET /api/stream/test/{timeout}.ts
```

## Parameters

- `{timeout}` - Integer representing seconds:
  - `0` = No timeout (stream runs indefinitely, shows runtime counter)
  - `10` = Stream runs for 10 seconds then terminates (shows countdown)
  - `30` = Stream runs for 30 seconds then terminates (shows countdown)
  - etc.

## Examples

### Infinite test stream (shows runtime counter)
```
http://localhost:36400/api/stream/test/0.m3u8
```

### 30-second test stream (shows countdown)
```
http://localhost:36400/api/stream/test/30.m3u8
```

### 12-second test stream (shows countdown)
```
http://localhost:36400/api/stream/test/12.m3u8
```

## Stream Content

- **No timeout (0)**: Shows runtime counter counting up (Runtime: 00:00, 00:04, 00:08, etc.)
- **With timeout**: Shows countdown timer counting down to zero (Countdown: 00:30, 00:26, 00:22, etc.)
- **Resolution**: 720p (1280x720) with H.264 video and AAC audio
- **Format**: MPEG-TS segments suitable for HLS streaming
- **Segment Duration**: 4 seconds per segment
- **Video**: H.264 Constrained Baseline profile, 25fps, test pattern with timer overlay
- **Audio**: AAC 128kbps, 1kHz test tone

## Features

- **FFmpeg Integration**: Uses FFmpeg for proper video generation with timer overlay
- **HLS Segmented Streaming**: Proper HLS playlist with individual TS segments
- **Connection Monitoring**: Automatically stops when client disconnects
- **Logging**: Comprehensive logging for debugging stream behavior
- **CORS Support**: Includes CORS headers for web player compatibility
- **Real-time Generation**: Segments generated on-demand

## Testing with Video Players

### VLC
1. Open VLC
2. Media → Open Network Stream
3. Enter: `http://localhost:36400/api/stream/test/30.m3u8`
4. Click Play

### FFplay
```bash
# Test 30-second countdown stream
ffplay http://localhost:36400/api/stream/test/30.m3u8

# Test infinite runtime stream  
ffplay http://localhost:36400/api/stream/test/0.m3u8
```

### Web Players (HLS.js, Video.js, etc.)
The `.m3u8` playlist format is compatible with most web-based HLS players.

### cURL Testing
```bash
# Download first segment
curl "http://localhost:36400/api/stream/test/30/segment_0.ts" -o segment.ts

# Verify segment with ffprobe
ffprobe segment.ts
```

## Use Cases

1. **Stream Stability Testing**: Test how your application handles streams that suddenly terminate
2. **Timeout Testing**: Verify stream timeout handling with different durations  
3. **Player Compatibility**: Test different video players with a known-good stream
4. **Network Testing**: Use as a test stream for network performance analysis
5. **Development**: Quick test stream without needing external sources
6. **HLS Testing**: Test HLS playlist parsing and segment fetching

## Technical Details

### Playlist Structure
- **VOD Playlist**: For streams with timeout (finite duration)
- **Live-like Playlist**: For infinite streams (shows first 10 segments)
- **Segment Duration**: 4 seconds each
- **Target Duration**: 4 seconds

### Video Specifications
- **Container**: MPEG-TS
- **Video Codec**: H.264 (Constrained Baseline, Level 3.0)
- **Audio Codec**: AAC-LC
- **Resolution**: 1280x720 (720p)
- **Frame Rate**: 25 fps
- **Pixel Format**: YUV420P

## Logs

All stream activity is logged to Laravel's standard logging system with the following information:
- Segment generation start/completion
- FFmpeg command execution
- Connection status and errors
- Process timeouts and failures

## Troubleshooting

### No Video Content
- Ensure FFmpeg is installed and available in PATH
- Check Laravel logs for FFmpeg errors
- Verify the endpoint returns HTTP 200 with `video/mp2t` content-type

### Player Won't Load Stream
- Test playlist URL directly in browser (should show M3U8 content)
- Verify individual segment URLs are accessible
- Check CORS headers if testing from web browsers

### Stream Stops Unexpectedly
- Check Laravel logs for connection abort messages
- Verify timeout parameter is correct
- Test with a longer timeout value
