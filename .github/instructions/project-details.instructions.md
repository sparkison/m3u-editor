---
applyTo: '**'
---
This app is a Laravel-based web application. It works with SQLite, MySQL, and PostgreSQL databases. It uses Redis for caching. It uses Horizon for queue management and Reverb for websockets. It uses Laravel Filament for the admin UI/UX.

The primary URL for testing and debugging is: http://m3ueditor.test, and the log files are appended with the date. Streaming related logs should be in the ffmpeg log, otherwise logging output will be in the laravel log file. The app is meant to run in a Docker container, but also works running locally (as it is currently) on macOS. It's running via NGINX, and using Reverb for websockets and Laravel Horizon for queue worker. It's also using Laravel Filament for the admin UI/UX.

This app is designed to be a web-based editor for M3U playlists, which are commonly used for streaming media content. The app allows users to create, edit, and manage M3U playlists through a user-friendly interface. It supports features such as adding, removing, and rearranging playlist items, as well as saving and exporting the playlists in M3U format, or via the Xtream API.

It provides several key "bonus" features over general Playlist and EPG management, including:
1. **Proxy functionality**: The app can act as a proxy server, allowing users to use m3u editor as a proxy to play the content and re-stream it to other clients.
2. **Xtream API**: It supports Xtream API, which is a popular protocol for streaming media content. This allows users to integrate their Xtream-based services with the app.
3. **Channel failover**: The app includes a channel failover feature, which allows assigning other channels to be used in case of playback failure with the primary channel.
4. **Shared/buffered proxy**: An enhanced proxy feature that allows multiple users to share a single proxy connection, reducing bandwidth usage and improving performance.