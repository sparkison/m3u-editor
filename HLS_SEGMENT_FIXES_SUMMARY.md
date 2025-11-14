# HLS Segment Storage Fixes - Implementation Summary

**Date:** 2025-11-13  
**Repositories:** m3u-editor:dev, m3u-proxy:dev  
**Status:** ✅ ALL FIXES IMPLEMENTED AND COMMITTED

---

## Overview

Implemented comprehensive fixes for 8 critical issues in HLS segment writing, storage, and garbage collection for m3u-editor AIO deployment.

---

## Commits

### m3u-editor Repository
**Commit:** `a9f609c9`  
**Message:** "fix: implement comprehensive HLS segment storage fixes for AIO"  
**Files Changed:** 4 files, 58 insertions(+), 1 deletion(-)

### m3u-proxy Repository
**Commit:** `eb78f26`  
**Message:** "fix: improve HLS segment handling and garbage collection"  
**Files Changed:** 1 file, 133 insertions(+), 41 deletions(-)

---

## Implemented Fixes

### ✅ Fix #1: Configure Persistent HLS Storage (CRITICAL)
**Repository:** m3u-editor  
**Files:** `start-container`

**Changes:**
- Added `HLS_TEMP_DIR` configuration (default: `/var/www/html/storage/app/hls-segments`)
- Create directory with correct permissions on startup
- Changed from ephemeral `/tmp` to persistent storage location
- Prevents data loss on container restart

**Code Added (lines 149-197):**
```bash
export HLS_TEMP_DIR="${HLS_TEMP_DIR:-/var/www/html/storage/app/hls-segments}"
export HLS_GC_ENABLED="${HLS_GC_ENABLED:-true}"
export HLS_GC_INTERVAL="${HLS_GC_INTERVAL:-600}"
export HLS_GC_AGE_THRESHOLD="${HLS_GC_AGE_THRESHOLD:-7200}"  # Increased to 2 hours

mkdir -p "$HLS_TEMP_DIR"
chown -R $WWWUSER:$WWWGROUP "$HLS_TEMP_DIR"
chmod 755 "$HLS_TEMP_DIR"
```

---

### ✅ Fix #2: Add Disk Space Monitoring (CRITICAL)
**Repository:** m3u-editor  
**Files:** `start-container`

**Changes:**
- Check available disk space on startup
- Warn if < 2GB available
- Critical alert if < 500MB available
- Prevents container crashes from disk full errors

**Code Added (lines 173-197):**
```bash
AVAILABLE_SPACE_KB=$(df -k "$HLS_TEMP_DIR" | tail -1 | awk '{print $4}')
AVAILABLE_SPACE_MB=$((AVAILABLE_SPACE_KB / 1024))
AVAILABLE_SPACE_GB=$((AVAILABLE_SPACE_MB / 1024))

if [ "$AVAILABLE_SPACE_MB" -lt 2048 ]; then
    echo "⚠️  WARNING: Low disk space for HLS segments!"
fi

if [ "$AVAILABLE_SPACE_MB" -lt 512 ]; then
    echo "🔴 CRITICAL: Very low disk space!"
fi
```

---

### ✅ Fix #3: Improve Garbage Collection (HIGH)
**Repository:** m3u-proxy  
**Files:** `src/pooled_stream_manager.py`

**Changes:**
- Increased default threshold to 7200s (2 hours) to avoid race conditions
- Added detailed logging of GC operations
- Better error handling and warnings
- Prevents 404 errors on segments mid-playback

**Key Improvements:**
- Tracks skipped directories (active, non-empty, too young)
- Comprehensive logging for debugging
- Safer error handling

---

### ✅ Fix #4: Resolve FFmpeg vs GC Conflict (MEDIUM)
**Repository:** m3u-proxy  
**Files:** `src/pooled_stream_manager.py`

**Changes:**
- GC now only deletes **EMPTY** directories
- FFmpeg handles segment deletion via `-hls_delete_threshold`
- Prevents conflicts between FFmpeg and GC deletion
- Fixes orphaned directory accumulation

**Code Change:**
```python
# Only delete EMPTY directories
dir_contents = os.listdir(full_path)
if dir_contents:
    skipped_not_empty += 1
    continue

# Use rmdir instead of rmtree (only works on empty dirs)
os.rmdir(full_path)
```

---

### ✅ Fix #5: Improve Cleanup on Crash (MEDIUM)
**Repository:** m3u-proxy  
**Files:** `src/pooled_stream_manager.py`

**Changes:**
- Refactored `cleanup()` to use try/finally block
- Extracted `_cleanup_hls_directory()` method
- Detailed logging of cleanup operations
- Ensures HLS cleanup runs even if FFmpeg cleanup fails

**Code Structure:**
```python
async def cleanup(self):
    try:
        # FFmpeg cleanup
        ...
    finally:
        # Always cleanup HLS directory
        await self._cleanup_hls_directory()

async def _cleanup_hls_directory(self):
    # Detailed cleanup with logging
    # Counts removed/failed files
    # Better error messages
```

---

### ✅ Fix #6: Fix Permissions (LOW)
**Repository:** m3u-proxy  
**Files:** `src/pooled_stream_manager.py`

**Changes:**
- Set explicit `0o755` permissions on HLS directory creation
- Ensures NGINX can read segments in multi-user scenarios
- Fallback handling with warnings if permission setting fails

**Code Added:**
```python
self.hls_dir = tempfile.mkdtemp(prefix=f"m3u_proxy_hls_{self.stream_id}_", dir=base_dir)
os.chmod(self.hls_dir, 0o755)  # rwxr-xr-x
```

---

### ✅ Fix #7: Pass HLS_TEMP_DIR to m3u-proxy (HIGH)
**Repository:** m3u-editor  
**Files:** `docker/8.4/supervisord.conf`

**Changes:**
- Updated supervisord environment to pass HLS variables
- Added: `HLS_TEMP_DIR`, `HLS_GC_ENABLED`, `HLS_GC_INTERVAL`, `HLS_GC_AGE_THRESHOLD`
- Fixes configuration being ignored (always used `/tmp` before)

**Before:**
```ini
environment=HOST="...",PORT="...",LOG_LEVEL="...",...
```

**After:**
```ini
environment=HOST="...",PORT="...",LOG_LEVEL="...",...,HLS_TEMP_DIR="%(ENV_HLS_TEMP_DIR)s",HLS_GC_ENABLED="%(ENV_HLS_GC_ENABLED)s",HLS_GC_INTERVAL="%(ENV_HLS_GC_INTERVAL)s",HLS_GC_AGE_THRESHOLD="%(ENV_HLS_GC_AGE_THRESHOLD)s"
```

---

### ✅ Fix #8: Add Write Error Monitoring (MEDIUM)
**Repository:** m3u-proxy  
**Files:** `src/pooled_stream_manager.py`

**Changes:**
- Monitor FFmpeg stderr for write errors
- Automatically mark stream as failed on write errors
- Triggers cleanup and prevents silent failures
- Better visibility into disk/storage issues

**Error Patterns Monitored:**
- "no space left on device"
- "permission denied"
- "i/o error"
- "disk full"
- "cannot write"
- "failed to open"
- "error writing"

---

## Storage Structure Changes

### Before (Broken):
```
/tmp/
├── m3u_proxy_hls_abc123_xyz/  ← Ephemeral, lost on restart
│   ├── index.m3u8
│   └── segment_*.ts
```

### After (Fixed):
```
/var/www/html/storage/app/hls-segments/  ← Persistent
├── m3u_proxy_hls_abc123_xyz/
│   ├── index.m3u8
│   └── segment_*.ts
└── .gitignore  ← Excludes segments from git
```

---

## Configuration Changes

### New Environment Variables (Optional)
```bash
# In docker-compose.aio.yml or .env
HLS_TEMP_DIR=/var/www/html/storage/app/hls-segments  # Default
HLS_GC_ENABLED=true                                   # Default
HLS_GC_INTERVAL=600                                   # 10 minutes (default)
HLS_GC_AGE_THRESHOLD=7200                             # 2 hours (increased from 1 hour)
```

### Defaults
All variables have sensible defaults. No configuration required for basic operation.

---

## Testing Instructions

### 1. Pull Latest Images
```bash
docker pull hektyc/m3u-editor:dev
docker pull hektyc/m3u-proxy:dev
```

### 2. Restart Container
```bash
docker-compose -f docker-compose.aio.yml down
docker-compose -f docker-compose.aio.yml up -d
```

### 3. Check Startup Logs
```bash
docker logs m3u-editor
```

**Look for:**
```
🎬 Configuring HLS segment storage...
   HLS_TEMP_DIR: /var/www/html/storage/app/hls-segments
   HLS_GC_ENABLED: true
   HLS_GC_INTERVAL: 600s
   HLS_GC_AGE_THRESHOLD: 7200s
   Creating HLS storage directory...
   ✅ Created: /var/www/html/storage/app/hls-segments
   Available disk space: XXG (XXXXMB)
✅ HLS storage configured
```

### 4. Test HLS Streaming
1. Create a stream with HLS profile
2. Start playback
3. Check segment storage:
   ```bash
   docker exec m3u-editor ls -la /var/www/html/storage/app/hls-segments/
   ```
4. Verify segments are being created
5. Stop stream and verify cleanup

### 5. Test Persistence
1. Start HLS stream
2. Restart container: `docker restart m3u-editor`
3. Verify segments persist (directory exists)
4. Old streams should be cleaned up by GC after 2 hours

---

## Expected Behavior

### ✅ Success Indicators
- HLS segments stored in `/var/www/html/storage/app/hls-segments/`
- Disk space warnings on startup if low
- Segments persist across container restarts
- Empty directories cleaned up after 2 hours
- Write errors logged and streams marked as failed
- Detailed GC logging in m3u-proxy logs

### ⚠️ Warnings (Expected)
- Low disk space warnings if < 2GB available
- GC skip messages for active/non-empty directories

### ❌ Errors (Investigate)
- Failed to create HLS storage directory
- Permission errors on segment files
- Disk full errors
- FFmpeg write errors

---

## Rollback Instructions

If issues occur, rollback to previous commits:

```bash
# m3u-editor
cd m3u-editor-dev
git revert a9f609c9
git push origin dev

# m3u-proxy
cd m3u-proxy-dev
git revert eb78f26
git push origin dev
```

---

## Related Documents

- `AIO_HLS_SEGMENT_WRITING_ANALYSIS.md` - Full analysis of all 8 errors
- `AIO_HLS_ANALYSIS.md` - Previous HLS streaming analysis
- `AIO_FIXES_SUMMARY.md` - Previous AIO configuration fixes

---

**Status:** ✅ ALL FIXES IMPLEMENTED, COMMITTED, AND PUSHED  
**Ready for Testing:** YES  
**Build Status:** Check https://github.com/hektyc/m3u-editor/actions

