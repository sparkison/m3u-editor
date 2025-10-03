# Auto-Merge Channels Functionality

## Overview

The Auto-Merge Channels functionality automatically merges channels with the same stream ID into a single master channel with failover relationships after each playlist sync. This feature helps streamline playlist management and improves streaming reliability.

## Features

### Core Functionality
- **Automatic Channel Merging**: Channels with identical stream IDs are automatically merged
- **Failover Channel Deactivation**: Option to automatically disable failover channels 
- **Smart Optimization**: Excludes already processed channels for better performance
- **Resolution-Based Prioritization**: Option to prioritize channels based on stream resolution
- **Complete Re-merge**: Force full reprocessing of all channels when needed

### Configuration Options

#### Playlist Settings
- **Auto-merge channels after sync**: Enable/disable the auto-merge functionality
- **Deactivate failover channels**: Automatically disable channels used as failovers
- **Prioritize by resolution**: Use stream resolution to determine master channel priority
- **Force complete re-merge**: Reprocess all channels instead of just new ones

## How It Works

### 1. Trigger Mechanism
The auto-merge process is triggered automatically when:
- A playlist sync completes successfully (`SyncCompleted` event)
- The playlist has `auto_merge_channels_enabled = true`
- The playlist sync status is `Status::Completed`

### 2. Channel Selection Logic
The system identifies channels for merging by:
- Grouping channels by their stream ID (either `stream_id_custom` or `stream_id`)
- Excluding channels that are already configured as failovers (unless force re-merge is enabled)
- Processing only channels within the specified playlists

### 3. Master Channel Selection
Priority rules for selecting the master channel:

**With Resolution Check (when "Order by Resolution" is enabled):**
⚠️ **IMPORTANT**: This option will analyze each stream to determine resolution, which can cause rate limiting or blocking with IPTV providers. Only use this option when necessary and with providers that allow stream analysis.

1. Find all channels with the highest resolution (requires stream analysis)
2. If a "Preferred Playlist" is selected: Take the first channel from that playlist with highest resolution
3. If no preferred playlist or no channel with highest resolution found there: Take the first channel with highest resolution (sorted by ID for consistency)

**Without Resolution Check (Default behavior - RECOMMENDED for IPTV):**
This is the safe option that doesn't require accessing streams, preventing rate limiting issues.

1. If a "Preferred Playlist" is selected: Take the first channel from that playlist (sorted by ID)
2. If no preferred playlist: Take the first channel based on playlist priority, then sorted by ID

**For a single playlist:**
- With Resolution Check: Channel with highest resolution becomes master (⚠️ requires stream analysis)
- Without Resolution Check: The first channel (sorted by ID) becomes master (✅ safe for IPTV)

### 4. Failover Management
- Remaining channels become failovers for the master channel
- **Improved sorting**: Failovers are sorted with multiple criteria for consistency:
  - With resolution check: Resolution (desc) → Playlist priority → Channel ID (⚠️ requires stream analysis)
  - Without resolution check: Playlist priority → Channel ID (✅ safe for IPTV)
- If deactivation is enabled, failover channels are automatically disabled
- Existing failover relationships are updated or created as needed

**IPTV Considerations:**
- Default behavior (without resolution check) is recommended for IPTV providers to avoid rate limiting
- Resolution checking should only be used when the provider allows stream analysis without restrictions

## Performance Optimizations

### Smart Channel Filtering
- Excludes channels already configured as failovers from master selection
- Reduces processing time for playlists with existing merge relationships
- Can be overridden with "Force complete re-merge" option

### Efficient Database Queries
- Uses cursor-based iteration for large channel sets
- Bulk operations for creating failover relationships
- Single query to identify existing failover channels

### IPTV-Specific Optimizations
- **Default mode (no resolution check)**: Fast processing with no stream access required
- **Resolution mode**: Only use when absolutely necessary and provider allows stream analysis
- **Rate limiting protection**: Default behavior prevents stream analysis to avoid provider restrictions

## Configuration

### Database Fields
New fields added to the `playlists` table:
- `auto_merge_channels_enabled` (boolean): Enable auto-merge functionality
- `auto_merge_deactivate_failover` (boolean): Deactivate failover channels
- `auto_merge_config` (JSON): Advanced configuration options

### Example Configuration
```php
$playlist->update([
    'auto_merge_channels_enabled' => true,
    'auto_merge_deactivate_failover' => true,
    'auto_merge_config' => [
        'check_resolution' => false,
        'force_complete_remerge' => false
    ]
]);
```

## User Interface

### Playlist Form Fields
Located in the sync settings section of playlist creation/editing:

1. **Auto-merge channels after sync** toggle
   - Main enable/disable switch
   - Helper text explains functionality

2. **Deactivate failover channels** toggle
   - Visible only when auto-merge is enabled
   - Controls whether failover channels are disabled

3. **Advanced Settings** (expandable section)
   - **Prioritize by resolution**: Use stream analysis for master selection
   - **Force complete re-merge**: Reprocess all channels regardless of existing state

### Manual Merge Interface
Located in Channels and VODs list pages:

1. **Merge Same ID** action
   - **Preferred Playlist**: Select playlist to prioritize as master during merge
   - **Failover Playlists**: One or more playlists to use as failover sources
   - **Order by Resolution**: ⚠️ Enable resolution-based prioritization (may cause IPTV rate limiting)
   - **Deactivate Failover Channels**: Automatically disable channels used as failovers

## Testing Locally

### Prerequisites
1. Laravel application running locally
2. Database with playlists and channels
3. Queue worker running (for background job processing)

### Manual Testing Steps

1. **Setup Test Data**
   ```bash
   php test_auto_merge.php
   ```

2. **Verify Settings in UI**
   - Navigate to Playlists → Edit [Playlist Name]
   - Check auto-merge settings are visible and functional
   - Enable the desired options

3. **Test Sync Trigger**
   - Manually sync the playlist from the UI
   - Monitor queue workers and logs
   - Check notifications for merge results

4. **Verify Results**
   - Check Channels section
   - Look for master/failover relationships
   - Verify channel enabled/disabled states

### Expected Results
- Channels with same stream ID grouped under one master
- Failover channels disabled if option is enabled
- Notification showing merge results
- Proper failover relationships in database

## Troubleshooting

### Common Issues

1. **Auto-merge not triggering**
   - Verify `auto_merge_channels_enabled` is true
   - Check playlist sync completed successfully
   - Ensure queue workers are running

2. **No channels being merged**
   - Check channels have matching stream IDs
   - Verify channels are not already in failover relationships
   - Try enabling "Force complete re-merge"

3. **Performance issues**
   - Consider disabling resolution checking for large playlists
   - Ensure database indexes on stream_id columns
   - Monitor queue worker memory usage

### Debugging

Enable detailed logging by checking:
- Laravel logs in `storage/logs/`
- Queue job failures in Horizon dashboard
- Database changes in `channel_failovers` table

## API Integration

### Job Dispatch
```php
use App\Jobs\MergeChannels;

dispatch(new MergeChannels(
    user: $user,
    playlists: collect([['playlist_failover_id' => $playlist->id]]),
    playlistId: $playlist->id,
    checkResolution: false,
    deactivateFailoverChannels: true,
    forceCompleteRemerge: false
));
```

### Event Listener
The auto-merge is triggered via the `SyncListener` which handles `SyncCompleted` events:

```php
// In app/Listeners/SyncListener.php
private function handleAutoMergeChannels(\App\Models\Playlist $playlist): void
{
    // Configuration and job dispatch logic
}
```

## Migration

The database migration adds the required fields:

```bash
php artisan migrate
```

This adds:
- `auto_merge_channels_enabled` boolean field
- `auto_merge_deactivate_failover` boolean field  
- `auto_merge_config` JSON field

## Security Considerations

- Auto-merge only processes channels owned by the playlist user
- All database operations include user_id constraints
- Job parameters are validated and sanitized
- Failover relationships maintain proper foreign key constraints

## Performance Impact

### Minimal Impact Scenarios
- Small playlists (< 1000 channels)
- Playlists with few duplicate stream IDs
- Existing merge relationships in place

### Higher Impact Scenarios
- Large playlists (> 10000 channels) 
- Resolution checking enabled
- Force complete re-merge enabled
- Many channels with duplicate stream IDs

### Mitigation Strategies
- Process during off-peak hours
- Use queue workers with appropriate memory limits
- Enable optimizations (disable force re-merge)
- Monitor database performance during processing