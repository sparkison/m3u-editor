# EPG Optimization Implementation

## Overview

The EPG system has been optimized with a comprehensive caching solution that dramatically improves performance for large EPG files. This document outlines the implementation and usage.

## Key Features

### 1. EPG Cache Service (`EpgCacheService`)
- **Location**: `app/Services/EpgCacheService.php`
- **Purpose**: Provides high-performance caching for EPG data using JSON files
- **Benefits**: 
  - Instant data retrieval instead of XML parsing
  - Date-chunked programme storage for efficient access
  - Memory-efficient pagination support
  - Automatic cache validation based on file modification times

### 2. Optimized API Controllers

#### EPG API Controller (`EpgApiController`)
- **Original Method**: `getData(string $uuid, Request $request)`
  - Returns EPG data for a specific EPG with pagination
  - URL: `GET /api/epg/{uuid}/data`
  - Now uses cache for instant response

- **New Method**: `getDataForPlaylist(string $uuid, Request $request)`
  - Returns EPG data for all enabled channels in a playlist
  - URL: `GET /api/epg/playlist/{uuid}/data`
  - Aggregates data from multiple EPGs based on playlist channel mappings
  - Same response format as `getData` for consistency

#### EPG Generate Controller (`EpgGenerateController`)
- **Optimization**: Now uses cache when available, falls back to XML parsing
- **Performance**: Dramatically faster EPG XML generation for playlists

### 3. Automatic Cache Generation
- **Integration**: Added to `ProcessEpgImport` job
- **Trigger**: Cache is automatically generated after EPG import/refresh
- **Manual Command**: `php artisan epg:cache-generate {uuid}`

## Usage Examples

### 1. Get EPG Data for Specific EPG
```javascript
// Frontend JavaScript
fetch('/api/epg/9f21e8bd-921c-452f-bec7-14fc0144c51b/data?page=1&per_page=50&start_date=2025-07-23')
  .then(response => response.json())
  .then(data => {
    console.log('Channels:', data.channels);
    console.log('Programmes:', data.programmes);
    console.log('Pagination:', data.pagination);
  });
```

### 2. Get EPG Data for Playlist Channels
```javascript
// Frontend JavaScript
fetch('/api/epg/playlist/playlist-uuid-here/data?page=1&per_page=50&start_date=2025-07-23')
  .then(response => response.json())
  .then(data => {
    console.log('Playlist:', data.playlist);
    console.log('Channels:', data.channels);
    console.log('Programmes:', data.programmes);
    console.log('Cache Info:', data.cache_info);
  });
```

### 3. Manual Cache Generation
```bash
# Generate cache for specific EPG
php artisan epg:cache-generate 9f21e8bd-921c-452f-bec7-14fc0144c51b
```

## Response Format

Both endpoints return data in the same format for consistency:

```json
{
  "epg": {                    // Only for EPG endpoint
    "id": 1,
    "name": "EPG Name",
    "uuid": "uuid-here"
  },
  "playlist": {               // Only for playlist endpoint
    "id": 1,
    "name": "Playlist Name",
    "uuid": "uuid-here",
    "type": "App\\Models\\Playlist"
  },
  "date_range": {
    "start": "2025-07-23",
    "end": "2025-07-23"
  },
  "pagination": {
    "current_page": 1,
    "per_page": 50,
    "total_channels": 23588,
    "returned_channels": 50,
    "has_more": true,
    "next_page": 2
  },
  "channels": {
    "channel_id": {
      "id": "channel_id",
      "display_name": "Channel Name",
      "icon": "icon_url",
      "lang": "en"
    }
  },
  "programmes": {
    "channel_id": [
      {
        "channel": "channel_id",
        "start": "2025-07-23T10:00:00.000000Z",
        "stop": "2025-07-23T11:00:00.000000Z",
        "title": "Programme Title",
        "desc": "Programme Description",
        "category": "Category",
        "icon": "programme_icon_url"
      }
    ]
  },
  "cache_info": {
    "cached": true,
    "cache_created": 1753310102,
    "total_programmes": 200000,
    "programme_date_range": {
      "min_date": "2025-05-01",
      "max_date": "2025-07-28"
    }
  }
}
```

## Performance Improvements

### Before Optimization
- **EPG Viewing**: 10-15 seconds per page (XML parsing on every request)
- **Memory Usage**: High memory consumption during XML parsing
- **Scalability**: Poor performance with large EPG files (23,000+ channels)

### After Optimization
- **EPG Viewing**: Instant response (<1 second)
- **Memory Usage**: Minimal memory usage (JSON file reading)
- **Scalability**: Excellent performance regardless of EPG size
- **Cache Generation**: One-time cost during import/refresh

## Technical Details

### Cache Structure
```
storage/app/epg-cache/{epg_uuid}/v1/
├── channels.json                 # All channels data
├── programmes-2025-07-23.json   # Programmes for specific date
├── programmes-2025-07-24.json   # Programmes for another date
└── metadata.json                # Cache metadata and stats
```

### Safety Features
- **Memory Limits**: Configurable memory limits for large files
- **Processing Limits**: Prevents excessive processing (200,000 programmes max)
- **Error Handling**: Graceful fallback to XML parsing if cache fails
- **Cache Validation**: Automatic detection of stale cache based on file modification

## Maintenance

### Cache Management
- **Automatic**: Cache is regenerated when EPG files are updated
- **Manual**: Use `php artisan app:epg-cache-generate {id}` command
- **Cleanup**: Cache is automatically replaced when EPG is refreshed

### Monitoring
- All cache operations are logged for monitoring and debugging
- Cache metadata includes statistics about channels and programmes
- Performance metrics available in response `cache_info`
