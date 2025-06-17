<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MediaFlow Proxy Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the MediaFlow-style proxy implementation
    | within the m3u editor framework.
    |
    */

    // Enable/disable MediaFlow proxy functionality
    'enabled' => env('MEDIAFLOW_PROXY_ENABLED', true),

    // Microservice configuration
    'microservice' => [
        'enabled' => env('MEDIAFLOW_MICROSERVICE_ENABLED', false),
        'url' => env('MEDIAFLOW_MICROSERVICE_URL', 'http://localhost:3001'),
        'websocket_port' => env('MEDIAFLOW_WEBSOCKET_PORT', 3002),
        'timeout' => env('MEDIAFLOW_MICROSERVICE_TIMEOUT', 5),
    ],

    // Proxy behavior settings
    'proxy' => [
        'user_agent' => env('MEDIAFLOW_PROXY_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36'),
        'timeout' => env('MEDIAFLOW_PROXY_TIMEOUT', 30),
        'max_redirects' => env('MEDIAFLOW_PROXY_MAX_REDIRECTS', 5),
        'verify_ssl' => env('MEDIAFLOW_PROXY_VERIFY_SSL', true),
    ],

    // Stream processing settings
    'stream' => [
        'force_playlist_proxy' => env('MEDIAFLOW_FORCE_PLAYLIST_PROXY', false),
        'enable_hls_processing' => env('MEDIAFLOW_ENABLE_HLS_PROCESSING', true),
        'enable_failover' => env('MEDIAFLOW_ENABLE_FAILOVER', true),
        'bad_source_cache_seconds' => env('MEDIAFLOW_BAD_SOURCE_CACHE_SECONDS', 60),
    ],

    // Content routing strategy
    'routing' => [
        'strategy' => env('MEDIAFLOW_ROUTING_STRATEGY', 'mediaflow'), // mediaflow, direct, or hybrid
        'playlist_proxy_threshold' => env('MEDIAFLOW_PLAYLIST_PROXY_THRESHOLD', 1024), // bytes
    ],

    // Supported formats
    'formats' => [
        'video' => ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv', 'wmv', 'ts', 'm2ts'],
        'playlist' => ['m3u8', 'm3u', 'm3u_plus'],
        'audio' => ['mp3', 'aac', 'wav', 'flac', 'ogg'],
    ],

    // Performance and caching
    'cache' => [
        'manifest_ttl' => env('MEDIAFLOW_MANIFEST_CACHE_TTL', 60), // seconds
        'stream_info_ttl' => env('MEDIAFLOW_STREAM_INFO_TTL', 3600), // seconds
        'bad_source_ttl' => env('MEDIAFLOW_BAD_SOURCE_TTL', 60), // seconds
    ],

    // Logging and monitoring
    'logging' => [
        'enabled' => env('MEDIAFLOW_LOGGING_ENABLED', true),
        'level' => env('MEDIAFLOW_LOG_LEVEL', 'info'),
        'include_headers' => env('MEDIAFLOW_LOG_HEADERS', false),
        'include_body' => env('MEDIAFLOW_LOG_BODY', false),
    ],

    // Rate limiting
    'rate_limiting' => [
        'enabled' => env('MEDIAFLOW_RATE_LIMITING_ENABLED', true),
        'requests_per_minute' => env('MEDIAFLOW_REQUESTS_PER_MINUTE', 120),
        'requests_per_hour' => env('MEDIAFLOW_REQUESTS_PER_HOUR', 1000),
    ],
];
