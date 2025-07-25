# Stream Player Component

A reusable Livewire component for playing HLS and MPEG-TS streams with automatic format detection.

## Features

- **Multi-format Support**: Automatically detects and plays HLS (.m3u8) and MPEG-TS streams
- **Smart Library Selection**: Uses hls.js for HLS streams and mpegts.js for MPEG-TS streams
- **Fallback Support**: Falls back to native browser video support when libraries aren't available
- **Error Handling**: Comprehensive error handling with retry functionality
- **Responsive Design**: Works well on different screen sizes with proper aspect ratio
- **Dark Mode**: Full dark mode support matching the application theme

## Usage

### 1. Include the Component

Add the Livewire component to your Blade template:

```blade
@livewire('stream-player')
```

### 2. Trigger the Player

Dispatch an Alpine.js event to open the stream player:

```blade
<button @click="$dispatch('openStreamPlayer', channelData)">
    Play Stream
</button>
```

Where `channelData` should be an object containing:

```javascript
{
    url: 'https://example.com/stream.m3u8',
    format: 'hls', // or 'ts' for MPEG-TS
    title: 'Channel Name',
    display_name: 'Display Name',
    logo: 'https://example.com/logo.png' // optional
}
```

### 3. Example Integration

```blade
<template x-for="channel in channels">
    <div class="channel-item">
        <span x-text="channel.display_name"></span>
        <button 
            x-show="channel.url"
            @click="$dispatch('openStreamPlayer', channel)"
            class="play-button"
        >
            Play
        </button>
    </div>
</template>

@livewire('stream-player')
```

## Supported Formats

- **HLS**: `.m3u8` streams using hls.js library
- **MPEG-TS**: Transport stream using mpegts.js library
- **Native**: Fallback to browser native support

## Dependencies

The component requires the following JavaScript libraries to be loaded:

- `hls.js` - for HLS stream playback
- `mpegts.js` - for MPEG-TS stream playback

These are automatically included when you build the assets with `npm run build`.

## Error Handling

The player includes comprehensive error handling:

- Network errors
- Format unsupported errors
- Stream unavailable errors
- Automatic retry functionality

## Events

### Listening for Events

```javascript
// Listen for player events
@openStreamPlayer.window="handlePlayerOpen($event.detail)"
@closeStreamPlayer.window="handlePlayerClose()"
```

### Dispatching Events

```javascript
// Open player programmatically
$dispatch('openStreamPlayer', {
    url: 'stream-url',
    format: 'hls',
    title: 'Channel Name'
})

// Close player programmatically
$dispatch('closeStreamPlayer')
```

## Customization

The component can be customized by modifying:

- `/app/Livewire/StreamPlayer.php` - Backend logic
- `/resources/views/livewire/stream-player.blade.php` - Frontend template
- CSS classes in the template for styling

## Integration Examples

### EPG Viewer Integration

The stream player is integrated with the EPG viewer to show play buttons for channels with URLs:

```blade
<!-- Play Button in Channel List -->
<div x-show="channel.url">
    <button @click="$dispatch('openStreamPlayer', channel)">
        <svg><!-- play icon --></svg>
    </button>
</div>
```

### Manual Integration

For custom implementations:

```php
// In your Livewire component
public function playChannel($channelId)
{
    $channel = Channel::find($channelId);
    
    $this->dispatch('openStreamPlayer', [
        'url' => $channel->stream_url,
        'format' => $channel->stream_format,
        'title' => $channel->title,
        'logo' => $channel->logo_url
    ]);
}
```

## Troubleshooting

### Player Not Loading

1. Ensure assets are built: `npm run build`
2. Check browser console for JavaScript errors
3. Verify stream URL is accessible
4. Check if required libraries (hls.js, mpegts.js) are loaded

### Stream Won't Play

1. Verify the stream format matches the `format` parameter
2. Check if stream URL requires authentication
3. Test stream URL directly in browser
4. Check network connectivity

### Performance Issues

1. Consider stream quality/bitrate
2. Check network bandwidth
3. Monitor browser memory usage
4. Ensure proper cleanup when closing player
