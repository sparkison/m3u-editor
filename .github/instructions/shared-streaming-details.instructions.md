---
applyTo: '**'
---
The Shared Streaming functionality is a buffered streaming approach that mimics the functionality of the xTeVe proxy functionality.
It allows multiple clients to share the same upstream stream, reducing load from proxy services like ffmpeg, and improving overall streaming performance.

It also has many additional features and functions, including:
- **Shared Proxy**: Multiple users can share a single proxy connection, which reduces bandwidth usage and improves performance.
- **Buffered Streaming**: The app buffers the stream, allowing for smoother playback and reducing the risk of interruptions during streaming.
- **Channel Failover**: If the primary channel fails, the app can automatically switch to a backup channel, ensuring continuous streaming without interruptions.

The Shared Streaming feature is designed to work seamlessly with the M3U Editor app, providing users with an efficient and reliable way to manage and stream their media content. It is particularly useful for users who require high availability and performance in their streaming setups.

Some of the relevant files for the Shared Streaming functionality include:
- `app/Console/Commands/ManageSharedStreams.php`: The console command for managing shared streams.
- `app/Filament/Pages/SharedStreamMonitor.php`: The Filament page for monitoring shared streams.
- `app/Http/Controllers/SharedStreamController.php`: The main controller for handling shared streaming requests.
- `app/Http/Controllers/Api/SharedStreamApiController.php`: The API controller for handling shared streaming requests via API, or using with apps like Grafana.
- `app/Jobs/SharedStreamCleanup.php`: The job for cleaning up old shared streams.
- `app/Jobs/StreamBufferManager.php`: The job for managing the stream buffer.
- `app/Jobs/StreamMonitorUpdate.php`: The job for updating the stream monitor.
- `app/Models/SharedStream.php`: The model representing a shared stream instance.
- `app/Services/SharedStreamService.php`: The service class that contains the business logic for shared streaming.
