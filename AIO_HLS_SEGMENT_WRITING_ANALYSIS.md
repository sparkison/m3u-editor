# M3U-Editor AIO HLS Segment Writing Process - Comprehensive Analysis

**Date:** 2025-11-13  
**Scope:** HLS segment writing, storage, and garbage collection in AIO mode  
**Focus:** Identify all errors and issues in the segment writing process

---

## Executive Summary

This analysis examines how m3u-editor AIO writes HLS segments to disk, identifies 8 critical errors in the process, and provides recommendations for fixes.

**Key Findings:**
- ✅ HLS segments are written to `/tmp` by default (system temp directory)
- ⚠️ **No persistent storage** - segments lost on container restart
- ⚠️ **No disk space monitoring** - can fill up `/tmp` and crash container
- ⚠️ **Race conditions** in garbage collection
- ⚠️ **Permission issues** possible with multi-user scenarios
- ⚠️ **No cleanup on FFmpeg crash**

---

## 1. HLS Segment Writing Flow

### 1.1 Architecture Overview

```
Client Request (HLS Stream)
    ↓
m3u-editor (Laravel) - Creates stream via M3uProxyService
    ↓
m3u-proxy API (/transcode endpoint)
    ↓
PooledStreamManager.get_or_create_shared_stream()
    ↓
SharedTranscodingProcess.__init__()
    ↓
Creates temp directory: /tmp/m3u_proxy_hls_{stream_id}_{random}/
    ↓
FFmpeg starts writing segments to temp directory
    ↓
    ├─→ index.m3u8 (playlist)
    ├─→ segment_000.ts
    ├─→ segment_001.ts
    ├─→ segment_002.ts
    └─→ ... (continues)
    ↓
Client requests playlist → m3u-proxy reads index.m3u8 from disk
    ↓
Client requests segment → m3u-proxy reads segment_XXX.ts from disk
```

### 1.2 Key Code Locations

**m3u-proxy Repository:**
- `src/config.py` lines 48, 71: Temp directory configuration
- `src/pooled_stream_manager.py` lines 33-88: HLS directory creation
- `src/pooled_stream_manager.py` lines 133-164: FFmpeg output path configuration
- `src/pooled_stream_manager.py` lines 270-290: HLS watch loop
- `src/pooled_stream_manager.py` lines 312-323: Playlist reading
- `src/pooled_stream_manager.py` lines 414-428: HLS directory cleanup
- `src/pooled_stream_manager.py` lines 690-747: HLS garbage collection
- `src/stream_manager.py` lines 1546-1558: Segment file reading

**Configuration:**
```python
# src/config.py
TEMP_DIR: str = "/tmp/m3u-proxy-streams"  # Not used for HLS!
HLS_TEMP_DIR: Optional[str] = None        # Defaults to system tempdir
HLS_GC_ENABLED: bool = True
HLS_GC_INTERVAL: int = 600                # 10 minutes
HLS_GC_AGE_THRESHOLD: int = 3600          # 1 hour
```

---

## 2. Identified Errors

### ERROR #1: HLS Segments Written to Ephemeral /tmp ⚠️ **CRITICAL**

**Severity:** CRITICAL  
**Impact:** Data loss, container instability

**Problem:**
HLS segments are written to the system temp directory (`/tmp` or `tempfile.gettempdir()`) which is:
1. **Ephemeral** - Lost on container restart
2. **Not persistent** - No Docker volume mounted
3. **Shared** - Used by all processes in container

**Code Location:**
```python
# pooled_stream_manager.py lines 69-86
base_dir = None
if self.hls_base_dir:
    base_dir = self.hls_base_dir
else:
    try:
        base_dir = tempfile.gettempdir()  # Returns /tmp on Linux
    except Exception:
        base_dir = None

self.hls_dir = tempfile.mkdtemp(prefix=f"m3u_proxy_hls_{self.stream_id}_", dir=base_dir)
```

**Impact:**
- Container restart = all active HLS streams fail
- No way to recover segments
- Clients get 404 errors mid-stream

**Fix:**
Set `HLS_TEMP_DIR` to a persistent location:
```bash
# In start-container or supervisord.conf
export HLS_TEMP_DIR="/var/www/html/storage/app/hls-segments"
```

---

### ERROR #2: No Disk Space Monitoring ⚠️ **CRITICAL**

**Severity:** CRITICAL  
**Impact:** Container crash, service outage

**Problem:**
No monitoring or limits on disk space usage for HLS segments. Multiple concurrent streams can fill `/tmp` and crash the container.

**Calculation:**
```
Segment size: ~500KB (2-second segment at 2Mbps)
Playlist size: 30 segments
Per-stream storage: 30 * 500KB = 15MB

10 concurrent streams = 150MB
100 concurrent streams = 1.5GB
```

**Current State:**
- No disk space checks before writing
- No per-stream limits
- No total storage limits
- No alerts when space is low

**Impact:**
- `/tmp` fills up → container becomes unstable
- PostgreSQL, Redis, PHP-FPM all fail
- Entire AIO container crashes

**Fix:**
1. Add disk space monitoring in `_hls_watch_loop()`
2. Implement per-stream size limits
3. Add total storage limit (e.g., 2GB max)
4. Stop accepting new streams when limit reached

---

### ERROR #3: Garbage Collection Race Condition ⚠️ **HIGH**

**Severity:** HIGH  
**Impact:** 404 errors on segments, playback failures

**Problem:**
The HLS garbage collector (`_gc_hls_temp_dirs()`) can delete segments that are still being served to clients.

**Race Condition Scenario:**
```
T=0:     Client requests playlist (contains segment_000.ts)
T=3590:  Segment_000.ts is 59m 50s old
T=3600:  GC runs, sees segment_000.ts is 1 hour old
T=3600:  GC deletes segment_000.ts
T=3601:  Client requests segment_000.ts → 404 ERROR
```

**Code Location:**
```python
# pooled_stream_manager.py lines 734-741
age = now - mtime
if age > self.hls_gc_age_threshold:  # 3600 seconds
    try:
        shutil.rmtree(full_path)
        removed += 1
```

**Problem:**
- Uses directory `mtime` (modification time), not segment access time
- No check if segments are currently being served
- No grace period for active streams

**Impact:**
- Clients get 404 errors mid-playback
- Especially affects paused streams or slow clients

**Fix:**
1. Increase `HLS_GC_AGE_THRESHOLD` to 7200 (2 hours)
2. Check if directory is in `active_dirs` before deletion (already done)
3. Add per-segment access time tracking
4. Only delete segments not accessed in last N minutes

---

### ERROR #4: FFmpeg Segment Deletion vs GC Conflict ⚠️ **MEDIUM**

**Severity:** MEDIUM  
**Impact:** Disk space not reclaimed, orphaned files

**Problem:**
FFmpeg has its own segment deletion (`-hls_delete_threshold 5`) but m3u-proxy GC also tries to delete segments, causing conflicts.

**Current HLS Profile:**
```php
// app/Filament/Resources/StreamProfiles/Pages/ListStreamProfiles.php
'args' => '-hls_time 2 -hls_list_size 30 -hls_flags delete_threshold+program_date_time -hls_delete_threshold 5'
```

**Behavior:**
- FFmpeg keeps last 30 segments in playlist
- FFmpeg deletes segments older than 5 segments from end
- m3u-proxy GC deletes directories older than 1 hour

**Conflict:**
1. FFmpeg deletes old segments from directory
2. Directory still exists with newer segments
3. GC sees directory mtime (updated by new segments)
4. GC never deletes directory (mtime keeps updating)
5. **Result:** Directories accumulate over time

**Impact:**
- Disk space slowly fills up
- Old directories never cleaned up
- Manual cleanup required

**Fix:**
1. Let FFmpeg handle segment deletion (it's more reliable)
2. GC should only delete **empty** directories
3. Add check: `if not os.listdir(full_path): shutil.rmtree(full_path)`

---

### ERROR #5: No Cleanup on FFmpeg Crash ⚠️ **MEDIUM**

**Severity:** MEDIUM  
**Impact:** Orphaned directories, disk space waste

**Problem:**
If FFmpeg crashes or is killed, the HLS directory is not cleaned up immediately.

**Code Location:**
```python
# pooled_stream_manager.py lines 414-428
async def stop(self):
    # ... kill FFmpeg ...
    
    # Remove HLS directory if present and empty
    try:
        if self.hls_dir and os.path.isdir(self.hls_dir):
            for fname in os.listdir(self.hls_dir):
                try:
                    os.remove(os.path.join(self.hls_dir, fname))
                except Exception:
                    pass  # Silently fails!
```

**Problems:**
1. Cleanup only happens in `stop()` method
2. If process crashes, `stop()` may not be called
3. Errors are silently ignored (`except Exception: pass`)
4. No retry mechanism

**Impact:**
- Orphaned directories accumulate
- Disk space wasted
- Relies on GC to clean up (1 hour delay)

**Fix:**
1. Add `try/finally` block in process lifecycle
2. Log cleanup errors instead of silencing
3. Add cleanup to container shutdown hook
4. Reduce GC interval for crashed processes

---

### ERROR #6: Permission Issues with Multi-User Scenarios ⚠️ **LOW**

**Severity:** LOW  
**Impact:** Segment read failures in edge cases

**Problem:**
HLS directories are created with default permissions. If supervisord runs m3u-proxy as a different user than NGINX, permission issues can occur.

**Current Setup:**
```ini
# supervisord.conf line 105
[program:m3u-proxy]
user=%(ENV_SUPERVISOR_PHP_USER)s  # Usually 'sail' or 'www-data'
```

**Potential Issue:**
1. m3u-proxy creates `/tmp/m3u_proxy_hls_xxx/` as user `sail`
2. FFmpeg writes segments as user `sail`
3. NGINX tries to serve segments (runs as `nginx` user)
4. **Potential:** Permission denied if umask is restrictive

**Current State:**
- No explicit permission setting on directory creation
- Relies on default umask
- Works in most cases but not guaranteed

**Fix:**
```python
# After mkdtemp()
os.chmod(self.hls_dir, 0o755)  # rwxr-xr-x
```

---

### ERROR #7: No HLS_TEMP_DIR Environment Variable Passed ⚠️ **HIGH**

**Severity:** HIGH  
**Impact:** Configuration ignored, always uses /tmp

**Problem:**
The `HLS_TEMP_DIR` environment variable is NOT passed to m3u-proxy in supervisord configuration!

**Current supervisord.conf:**
```ini
# Line 114
environment=HOST="...",PORT="...",LOG_LEVEL="...",API_TOKEN="...",PUBLIC_URL="...",REDIS_HOST="...",REDIS_PORT="...",REDIS_DB="...",REDIS_ENABLED="...",ENABLE_TRANSCODING_POOLING="..."
```

**Missing:**
- `HLS_TEMP_DIR` is not in the environment list!
- Even if set in docker-compose, m3u-proxy never sees it
- Always falls back to `tempfile.gettempdir()` = `/tmp`

**Impact:**
- **Cannot configure HLS storage location**
- Always uses ephemeral `/tmp`
- No way to use persistent storage

**Fix:**
Add to supervisord.conf line 114:
```ini
environment=...,HLS_TEMP_DIR="%(ENV_HLS_TEMP_DIR)s"
```

---

### ERROR #8: No Segment Write Error Handling ⚠️ **MEDIUM**

**Severity:** MEDIUM  
**Impact:** Silent failures, no alerts

**Problem:**
FFmpeg writes segments to disk, but there's no monitoring of write errors (disk full, I/O errors, etc.).

**Current State:**
- FFmpeg writes to `hls_dir/index.m3u8` and `hls_dir/segment_XXX.ts`
- If write fails, FFmpeg may log to stderr
- m3u-proxy's `_log_stderr()` logs it but doesn't act on it
- No alerts, no failover, no client notification

**Scenarios:**
1. Disk full → FFmpeg can't write → stream stalls
2. I/O error → Partial segment written → Corrupted playback
3. Permission denied → FFmpeg crashes → Stream ends

**Impact:**
- Clients experience buffering/stalling
- No automatic recovery
- No visibility into the issue

**Fix:**
1. Monitor FFmpeg stderr for write errors
2. Implement disk space pre-check before starting stream
3. Add health check that verifies segments are being written
4. Trigger failover or stop stream on write errors

---

## 3. Storage Architecture Issues

### 3.1 Current Storage Layout

```
/tmp/
├── m3u_proxy_hls_abc123_xyz/
│   ├── index.m3u8
│   ├── segment_000.ts
│   ├── segment_001.ts
│   └── ...
├── m3u_proxy_hls_def456_uvw/
│   ├── index.m3u8
│   └── ...
└── ... (more streams)
```

**Problems:**
- ❌ Ephemeral (lost on restart)
- ❌ No Docker volume
- ❌ Shared with system temp files
- ❌ No size limits
- ❌ No monitoring

### 3.2 Recommended Storage Layout

```
/var/www/html/storage/app/hls-segments/  (Docker volume)
├── stream_abc123/
│   ├── index.m3u8
│   ├── segment_000.ts
│   └── ...
├── stream_def456/
│   └── ...
└── .gitignore
```

**Benefits:**
- ✅ Persistent across restarts
- ✅ Can be mounted as Docker volume
- ✅ Separate from system temp
- ✅ Can set size limits
- ✅ Can monitor usage

---

## 4. Garbage Collection Issues

### 4.1 Current GC Logic

```python
# pooled_stream_manager.py lines 690-747
async def _gc_hls_temp_dirs(self):
    tmpdir = tempfile.gettempdir()  # /tmp
    prefix = "m3u_proxy_hls_"
    
    # Build set of active dirs
    active_dirs = set()
    for p in self.shared_processes.values():
        if p.hls_dir:
            active_dirs.add(os.path.abspath(p.hls_dir))
    
    # Scan tmpdir
    for entry in os.listdir(tmpdir):
        if not entry.startswith(prefix):
            continue
        
        full_path = os.path.join(tmpdir, entry)
        
        # Skip active dirs
        if full_path in active_dirs:
            continue
        
        # Check age
        mtime = os.path.getmtime(full_path)
        age = now - mtime
        
        if age > self.hls_gc_age_threshold:  # 3600s = 1 hour
            shutil.rmtree(full_path)
```

**Issues:**
1. ⚠️ Uses `mtime` (modification time) - updated when new segments written
2. ⚠️ Deletes entire directory tree - can delete active segments
3. ⚠️ No check if segments are being served
4. ⚠️ 1-hour threshold too aggressive for paused streams

### 4.2 Recommended GC Logic

```python
async def _gc_hls_temp_dirs(self):
    # 1. Only delete EMPTY directories
    # 2. Use longer threshold (2 hours)
    # 3. Check last access time, not mtime
    # 4. Log what's being deleted
```

---

## 5. Recommended Fixes

### Priority 1: CRITICAL (Implement Immediately)

**Fix #1: Configure Persistent HLS Storage**
- Add `HLS_TEMP_DIR` to supervisord environment
- Set to `/var/www/html/storage/app/hls-segments`
- Create directory in start-container
- Add to .gitignore

**Fix #2: Add Disk Space Monitoring**
- Check available space before creating stream
- Reject new streams if < 500MB free
- Add cleanup when space low

**Fix #7: Pass HLS_TEMP_DIR to m3u-proxy**
- Update supervisord.conf line 114
- Add environment variable

### Priority 2: HIGH (Implement Soon)

**Fix #3: Improve Garbage Collection**
- Increase threshold to 7200s (2 hours)
- Only delete empty directories
- Add access time tracking

**Fix #5: Improve Cleanup on Crash**
- Add try/finally blocks
- Log cleanup errors
- Add shutdown hooks

### Priority 3: MEDIUM (Implement When Possible)

**Fix #4: Resolve FFmpeg vs GC Conflict**
- Let FFmpeg handle segment deletion
- GC only removes empty directories

**Fix #6: Fix Permissions**
- Set explicit permissions on directory creation
- Ensure NGINX can read segments

**Fix #8: Add Write Error Handling**
- Monitor FFmpeg stderr for errors
- Pre-check disk space
- Add health checks

---

## 6. Configuration Changes Needed

### 6.1 start-container Script

```bash
# Add after line 149 (after M3U_PROXY_PUBLIC_URL validation)

# Configure HLS segment storage
export HLS_TEMP_DIR="/var/www/html/storage/app/hls-segments"
mkdir -p "$HLS_TEMP_DIR"
chown -R $WWWUSER:$WWWGROUP "$HLS_TEMP_DIR"
chmod 755 "$HLS_TEMP_DIR"

# Configure HLS GC settings
export HLS_GC_ENABLED="true"
export HLS_GC_INTERVAL="600"           # 10 minutes
export HLS_GC_AGE_THRESHOLD="7200"     # 2 hours (increased from 1 hour)
```

### 6.2 supervisord.conf

```ini
# Update line 114 to include HLS configuration
environment=HOST="%(ENV_M3U_PROXY_HOST)s",PORT="%(ENV_M3U_PROXY_PORT)s",LOG_LEVEL="%(ENV_M3U_PROXY_LOG_LEVEL)s",API_TOKEN="%(ENV_M3U_PROXY_TOKEN)s",PUBLIC_URL="%(ENV_M3U_PROXY_PUBLIC_URL)s",REDIS_HOST="%(ENV_M3U_PROXY_REDIS_HOST)s",REDIS_PORT="%(ENV_M3U_PROXY_REDIS_PORT)s",REDIS_DB="%(ENV_M3U_PROXY_REDIS_DB)s",REDIS_ENABLED="%(ENV_M3U_REDIS_ENABLED)s",ENABLE_TRANSCODING_POOLING="%(ENV_M3U_ENABLE_TRANSCODING_POOLING)s",HLS_TEMP_DIR="%(ENV_HLS_TEMP_DIR)s",HLS_GC_ENABLED="%(ENV_HLS_GC_ENABLED)s",HLS_GC_INTERVAL="%(ENV_HLS_GC_INTERVAL)s",HLS_GC_AGE_THRESHOLD="%(ENV_HLS_GC_AGE_THRESHOLD)s"
```

### 6.3 .gitignore

```
# Add to storage/.gitignore
/app/hls-segments/*
!/app/hls-segments/.gitignore
```

---

**End of Analysis**

