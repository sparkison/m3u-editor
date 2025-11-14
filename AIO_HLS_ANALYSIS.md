# M3U-Editor AIO HLS Streaming Analysis

## Executive Summary

This document provides a comprehensive analysis of how m3u-editor AIO (All-In-One) uses its internal embedded m3u-proxy for HLS streaming, identifying all potential errors and configuration issues specific to the AIO deployment mode.

**Analysis Date:** 2025-11-13  
**Scope:** AIO mode only (embedded m3u-proxy)  
**Focus:** HLS output streaming errors and misconfigurations

---

## 1. AIO Architecture Overview

### 1.1 Container Structure

The AIO deployment runs **everything in a single Docker container**:

```
┌─────────────────────────────────────────────────────────────┐
│  m3u-editor AIO Container (Port 36400)                      │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   NGINX      │  │  PHP-FPM     │  │  PostgreSQL  │      │
│  │  (Port 36400)│  │  (Port 9000) │  │  (Port 5432) │      │
│  └──────┬───────┘  └──────────────┘  └──────────────┘      │
│         │                                                    │
│         │ Reverse Proxy: /m3u-proxy/* → 127.0.0.1:8085     │
│         ↓                                                    │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  m3u-proxy (Python/FastAPI)                          │  │
│  │  - Listens on: 127.0.0.1:8085 (localhost only)      │  │
│  │  - Managed by: supervisord                           │  │
│  │  - PUBLIC_URL: http://localhost:36400/m3u-proxy     │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │    Redis     │  │   Horizon    │  │   Reverb     │      │
│  │  (Port 36790)│  │   (Queue)    │  │  (Port 36800)│      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 Key Configuration Points

**Docker Compose (docker-compose.aio.yml):**

```yaml
environment:
    - M3U_PROXY_ENABLED=true # Enables embedded mode
    - M3U_PROXY_PORT=38085 # Internal port (default)
    - M3U_PROXY_HOST=localhost # Bind to localhost
    - M3U_PROXY_PUBLIC_URL=http://localhost:36400/m3u-proxy
    - M3U_PROXY_TOKEN=changeme
```

**Start Container Script (start-container):**

-   Lines 30-84: M3U Proxy configuration logic
-   Line 32: `M3U_PROXY_HOST=127.0.0.1` (embedded mode)
-   Line 33: `M3U_PROXY_PORT=8085` (internal port)
-   Lines 77-84: AUTO-GENERATES `M3U_PROXY_PUBLIC_URL` if not set

**Supervisord (docker/8.4/supervisord.conf):**

-   Lines 103-114: m3u-proxy program definition
-   Line 106: `autostart=%(ENV_M3U_PROXY_START_EMBEDDED)s`
-   Line 114: Environment variables passed to m3u-proxy

**NGINX (docker/8.4/nginx/laravel.conf):**

-   Lines 50-69: Reverse proxy configuration
-   Proxies `/m3u-proxy/*` to `127.0.0.1:8085`

---

## 2. HLS Streaming Flow in AIO Mode

### 2.1 Complete Request Path

```
Client Request
    ↓
http://localhost:36400/m3u-proxy/hls/{stream_id}/playlist.m3u8
    ↓
NGINX (Port 36400)
    ↓
Reverse Proxy: /m3u-proxy/* → http://127.0.0.1:8085/*
    ↓
m3u-proxy FastAPI (127.0.0.1:8085)
    ↓
GET /hls/{stream_id}/playlist.m3u8 (src/api.py:636)
    ↓
stream_manager.get_playlist_content()
    ↓
Rewrites segment URLs using PUBLIC_URL (src/api.py:679-709)
    ↓
Returns playlist with rewritten URLs:
    #EXTINF:2.0
    http://localhost:36400/m3u-proxy/hls/{stream_id}/segment?client_id=xxx&url=...
    ↓
Client requests segment
    ↓
GET /hls/{stream_id}/segment (src/api.py:749)
    ↓
stream_manager.proxy_hls_segment()
    ↓
Fetches from upstream and streams to client
```

### 2.2 URL Rewriting Logic

**Critical Code (src/api.py:679-709):**

```python
# Build base URL using settings.PUBLIC_URL
public_url = settings.PUBLIC_URL  # From environment
root_path = settings.ROOT_PATH    # Default: "/m3u-proxy"

if public_url:
    # Parse PUBLIC_URL
    public_with_scheme = public_url if public_url.startswith(('http://', 'https://'))
                         else f"http://{public_url}"
    parsed = urlparse(public_with_scheme)

    # Extract components
    scheme = parsed.scheme or 'http'
    host = parsed.hostname or ''
    url_port = parsed.port
    path = parsed.path or ''

    # CRITICAL: Prevent ROOT_PATH duplication
    if root_path and path.startswith(root_path):
        path = path[len(root_path):]

    # Build base URL
    base = f"{scheme}://{netloc}{path.rstrip('/')}"
```

**This is where PUBLIC_URL mismatches cause HLS failures!**

---

## 3. Identified HLS Errors in AIO Mode

### ERROR #1: PUBLIC_URL Auto-Generation Mismatch ⚠️ **CRITICAL**

**Severity:** HIGH  
**Impact:** HLS streams fail to load segments

**Problem:**
The `start-container` script auto-generates `M3U_PROXY_PUBLIC_URL` if not explicitly set:

```bash
# start-container lines 77-84
if [ -z "$M3U_PROXY_PUBLIC_URL" ]; then
  if [[ "$APP_URL" == *"https"* ]]; then
      export M3U_PROXY_PUBLIC_URL="${APP_URL}/m3u-proxy"
  else
      export M3U_PROXY_PUBLIC_URL="${APP_URL}:${APP_PORT}/m3u-proxy"
  fi
fi
```

**Scenarios that cause failure:**

1. **Reverse Proxy Setup:**

    - User runs AIO behind Caddy/Traefik/nginx
    - `APP_URL=https://streams.example.com`
    - Auto-generated: `M3U_PROXY_PUBLIC_URL=https://streams.example.com/m3u-proxy`
    - But m3u-editor config has: `M3U_PROXY_PUBLIC_URL=http://localhost:36400/m3u-proxy`
    - **Result:** Segment URLs point to wrong host, 404 errors

2. **Port Mismatch:**

    - `APP_URL=http://192.168.1.100`
    - `APP_PORT=36400`
    - Auto-generated: `M3U_PROXY_PUBLIC_URL=http://192.168.1.100:36400/m3u-proxy`
    - But user accesses via: `http://192.168.1.100:8080/m3u-proxy` (reverse proxy)
    - **Result:** Segment URLs have wrong port, connection refused

3. **HTTPS Mismatch:**
    - `APP_URL=https://example.com`
    - Auto-generated: `M3U_PROXY_PUBLIC_URL=https://example.com/m3u-proxy`
    - But reverse proxy terminates SSL, m3u-proxy sees HTTP
    - **Result:** Mixed content errors in browser

**Fix:** Use the new `validatePublicUrl()` method we just added!

---

### ERROR #2: ROOT_PATH Duplication in URLs ⚠️ **MEDIUM**

**Severity:** MEDIUM
**Impact:** 404 errors on segment requests

**Problem:**
m3u-proxy has `ROOT_PATH=/m3u-proxy` as default (src/config.py:27). When PUBLIC_URL already includes `/m3u-proxy`, the code tries to prevent duplication (src/api.py:697-699), but this can fail in edge cases.

**Failure Scenario:**

```python
# User sets:
PUBLIC_URL = "http://example.com/m3u-proxy"
ROOT_PATH = "/m3u-proxy"  # Default

# Code removes ROOT_PATH from path:
path = "/m3u-proxy"
if root_path and path.startswith(root_path):
    path = path[len(root_path):]  # path becomes ""

# But then segment URLs become:
http://example.com/hls/{stream_id}/segment  # Missing /m3u-proxy!
```

**Actual Behavior:**
The code at line 709 does `base = f"{scheme}://{netloc}{path.rstrip('/')}"`, which should preserve the path. However, if `path` becomes empty, the base URL loses the `/m3u-proxy` prefix.

**Impact:**

-   Playlist loads correctly (served by m3u-proxy)
-   Segment requests fail with 404 (NGINX doesn't route to m3u-proxy)

**Fix:**
Ensure PUBLIC_URL is set correctly WITHOUT the ROOT_PATH suffix:

```bash
# CORRECT:
PUBLIC_URL=http://example.com
ROOT_PATH=/m3u-proxy

# INCORRECT:
PUBLIC_URL=http://example.com/m3u-proxy
ROOT_PATH=/m3u-proxy
```

---

### ERROR #3: NGINX Proxy Configuration Issues ⚠️ **MEDIUM**

**Severity:** MEDIUM
**Impact:** Timeouts, buffering issues, connection drops

**Problem:**
The NGINX reverse proxy configuration (docker/8.4/nginx/laravel.conf:50-69) has good settings, but can still cause issues:

**Current Configuration:**

```nginx
location /m3u-proxy/ {
    proxy_pass http://${M3U_PROXY_NGINX_TARGET};  # 127.0.0.1:8085
    proxy_http_version 1.1;

    # Timeouts
    proxy_read_timeout 3600s;      # 1 hour
    proxy_connect_timeout 75s;
    proxy_send_timeout 3600s;

    # Buffering
    proxy_buffering off;           # Good for streaming
    proxy_request_buffering off;
}
```

**Issues:**

1. **No proxy_pass trailing slash:**

    - `proxy_pass http://127.0.0.1:8085;` (no trailing slash)
    - This preserves the `/m3u-proxy/` prefix when forwarding
    - m3u-proxy expects requests at `/hls/...` not `/m3u-proxy/hls/...`
    - **BUT:** m3u-proxy has `ROOT_PATH=/m3u-proxy`, so it handles this correctly
    - **Status:** ✅ Working as designed

2. **Missing Headers:**

    - No `X-Forwarded-Host` header
    - No `X-Forwarded-Port` header
    - m3u-proxy can't detect original request host/port
    - **Impact:** URL rewriting may use wrong host in some scenarios

3. **Buffer Size Limits:**
    - No `proxy_buffer_size` or `proxy_buffers` directives
    - Uses NGINX defaults (may be too small for large playlists)
    - **Impact:** Rare, but possible truncation of large playlists

**Fix:**
Add missing headers:

```nginx
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
```

---

### ERROR #4: m3u-editor Config vs m3u-proxy Environment Mismatch ⚠️ **HIGH**

**Severity:** HIGH
**Impact:** Complete HLS failure

**Problem:**
m3u-editor reads configuration from Laravel config (config/proxy.php), which reads from environment variables. m3u-proxy reads from its own environment variables set by supervisord.

**Configuration Flow:**

```
Docker Compose Environment Variables
    ↓
start-container script (processes and exports)
    ↓
    ├─→ Laravel .env file (for m3u-editor)
    │   ↓
    │   config/proxy.php
    │   ↓
    │   M3uProxyService.php
    │       - $this->apiBaseUrl = "localhost:8085"
    │       - $this->apiPublicUrl = "http://localhost:36400/m3u-proxy"
    │
    └─→ Supervisord environment (for m3u-proxy)
        ↓
        docker/8.4/supervisord.conf line 114
        ↓
        m3u-proxy process
            - settings.HOST = "127.0.0.1"
            - settings.PORT = 8085
            - settings.PUBLIC_URL = "http://localhost:36400/m3u-proxy"
```

**Mismatch Scenarios:**

1. **User updates .env but doesn't restart container:**

    - m3u-editor sees new PUBLIC_URL
    - m3u-proxy still has old PUBLIC_URL
    - **Result:** Segment URLs point to wrong location

2. **User sets M3U_PROXY_PUBLIC_URL in docker-compose but not in .env:**

    - m3u-proxy gets correct PUBLIC_URL
    - m3u-editor uses default/auto-generated PUBLIC_URL
    - **Result:** Validation fails, URLs mismatch

3. **Port mismatch:**
    - Docker compose: `M3U_PROXY_PORT=38085`
    - But start-container defaults to: `M3U_PROXY_PORT=8085`
    - **Result:** m3u-editor can't connect to m3u-proxy

**Fix:**
The new `validatePublicUrl()` method detects this! Run `php artisan m3u-proxy:status` to check.

---

### ERROR #5: Transcoded HLS Stream Pooling Issues ⚠️ **MEDIUM**

**Severity:** MEDIUM
**Impact:** Wrong quality streams, resource waste

**Problem:**
When using stream profiles for transcoding, m3u-editor creates transcoded HLS streams via m3u-proxy. The pooling logic (Redis-based) should prevent duplicate transcodes.

**Current Behavior (AFTER our fix):**

-   m3u-editor passes `profile_id` in metadata (app/Services/M3uProxyService.php:376)
-   m3u-proxy pools streams by `url + profile` (src/config.py:35)
-   ✅ **FIXED:** Different profiles no longer share streams

**Remaining Issue:**
m3u-proxy's `STREAM_SHARING_STRATEGY` is set to `url_profile` by default, which is correct. However, the profile identifier used is the FFmpeg args string, not the profile ID.

**Example:**

```php
// m3u-editor sends:
metadata = [
    'profile_id' => 5,
    'profile_identifier' => '-c:v libx264 -preset ultrafast ...'  // FFmpeg args
]

// m3u-proxy pools by:
pool_key = f"{url}:{profile_identifier}"  // Uses FFmpeg args, not ID
```

**Impact:**

-   If two profiles have identical FFmpeg args but different IDs, they'll be pooled together
-   This is actually CORRECT behavior (same transcoding = same output)
-   **Status:** ✅ Working as designed

---

### ERROR #6: HLS Segment Garbage Collection Race Condition ⚠️ **LOW**

**Severity:** LOW
**Impact:** Occasional 404 on segments

**Problem:**
m3u-proxy has HLS garbage collection enabled (src/config.py:65-67):

```python
HLS_GC_ENABLED = True
HLS_GC_INTERVAL = 600        # 10 minutes
HLS_GC_AGE_THRESHOLD = 3600  # 1 hour
```

**Race Condition:**

1. Client requests playlist at T=0
2. Playlist contains segment URLs for segments created at T=0
3. Client requests segment at T=3601 (1 hour + 1 second later)
4. GC has deleted the segment (age > 3600 seconds)
5. **Result:** 404 error

**Likelihood:** Very low (requires client to pause for 1+ hour)

**Fix:**
Increase `HLS_GC_AGE_THRESHOLD` to 7200 (2 hours) or disable GC entirely for live streams.

---

### ERROR #7: Default HLS Profile Not Optimized for AIO ⚠️ **LOW**

**Severity:** LOW
**Impact:** Higher latency, more buffering

**Problem:**
The default HLS profile (app/Filament/Resources/StreamProfiles/Pages/ListStreamProfiles.php:33-39) was optimized in our recent fix, but AIO mode has additional constraints:

**AIO Constraints:**

-   Single container = shared CPU/memory
-   FFmpeg competes with PHP-FPM, PostgreSQL, Redis, NGINX
-   Disk I/O is shared (HLS segments written to /tmp)

**Current Settings (AFTER our fix):**

```php
'args' => '-hls_time 2 -hls_list_size 30 -hls_flags delete_threshold+program_date_time -hls_delete_threshold 5 -hls_segment_filename segment_%03d.ts'
```

**Recommendations for AIO:**

-   Consider `-hls_time 3` (3-second segments) instead of 2 for less CPU usage
-   Consider `-hls_list_size 20` instead of 30 for less memory usage
-   Add `-preset ultrafast` for faster encoding (lower quality but less CPU)

**Status:** ✅ Already optimized, but can be tuned further for resource-constrained AIO

---

## 4. Configuration Validation Checklist

### 4.1 Required Environment Variables

**Docker Compose (.env or docker-compose.aio.yml):**

```bash
# Application
APP_URL=http://localhost              # Or your domain
APP_PORT=36400                        # External port

# M3U Proxy
M3U_PROXY_ENABLED=true                # MUST be true for AIO
M3U_PROXY_HOST=localhost              # MUST be localhost for AIO
M3U_PROXY_PORT=8085                   # Internal port (default)
M3U_PROXY_PUBLIC_URL=http://localhost:36400/m3u-proxy  # CRITICAL!
M3U_PROXY_TOKEN=<secure-random-token>
```

### 4.2 Validation Commands

**Check m3u-proxy is running:**

```bash
docker exec m3u-editor supervisorctl status m3u-proxy
# Expected: m3u-proxy RUNNING pid 123, uptime 0:05:00
```

**Check PUBLIC_URL configuration:**

```bash
docker exec m3u-editor php artisan m3u-proxy:status
# Should show PUBLIC_URL validation results
```

**Check m3u-proxy health:**

```bash
curl -H "X-API-Token: <your-token>" http://localhost:36400/m3u-proxy/health
# Expected: {"status":"healthy","public_url":"http://localhost:36400/m3u-proxy",...}
```

**Test HLS stream:**

```bash
# Get a stream URL from m3u-editor
curl "http://localhost:36400/m3u-proxy/hls/<stream-id>/playlist.m3u8"
# Should return m3u8 playlist with segment URLs
```

---

## 5. Common Misconfiguration Scenarios

### Scenario 1: Running Behind Reverse Proxy

**Setup:**

-   Caddy/Traefik/nginx in front of AIO container
-   External URL: `https://streams.example.com`
-   Internal URL: `http://localhost:36400`

**WRONG Configuration:**

```yaml
environment:
    - APP_URL=https://streams.example.com
    - M3U_PROXY_PUBLIC_URL=http://localhost:36400/m3u-proxy # WRONG!
```

**Result:** Segment URLs point to `http://localhost:36400/...` which clients can't reach.

**CORRECT Configuration:**

```yaml
environment:
    - APP_URL=https://streams.example.com
    - M3U_PROXY_PUBLIC_URL=https://streams.example.com/m3u-proxy # CORRECT!
```

---

### Scenario 2: LAN Access with Custom Port

**Setup:**

-   AIO running on `192.168.1.100:8080`
-   Accessed from LAN devices

**WRONG Configuration:**

```yaml
environment:
    - APP_URL=http://192.168.1.100
    - APP_PORT=36400
    # M3U_PROXY_PUBLIC_URL not set - auto-generates to http://192.168.1.100:36400/m3u-proxy
```

**Result:** Clients access via `:8080` but segments point to `:36400`.

**CORRECT Configuration:**

```yaml
environment:
    - APP_URL=http://192.168.1.100
    - APP_PORT=8080
    - M3U_PROXY_PUBLIC_URL=http://192.168.1.100:8080/m3u-proxy # Explicit!
```

---

### Scenario 3: Docker Network Mode

**Setup:**

-   AIO container in custom Docker network
-   Accessed via container name

**WRONG Configuration:**

```yaml
environment:
    - APP_URL=http://m3u-editor
    - M3U_PROXY_PUBLIC_URL=http://m3u-editor:36400/m3u-proxy
```

**Result:** Works inside Docker network, but not from host or external clients.

**CORRECT Configuration:**

```yaml
environment:
    - APP_URL=http://localhost # Or actual external IP/domain
    - M3U_PROXY_PUBLIC_URL=http://localhost:36400/m3u-proxy
```

---

## 6. Debugging Guide

### 6.1 Check m3u-proxy Logs

```bash
# View m3u-proxy logs
docker exec m3u-editor supervisorctl tail -f m3u-proxy

# Look for:
# - "Serving HLS playlist to client..."
# - "HLS segment request - Stream: ..."
# - "Error serving playlist: ..."
```

### 6.2 Check NGINX Logs

```bash
# View NGINX access logs
docker exec m3u-editor tail -f /var/log/nginx/access.log

# Look for:
# - GET /m3u-proxy/hls/... 200
# - GET /m3u-proxy/hls/... 404  # Bad sign!
# - GET /m3u-proxy/hls/... 502  # m3u-proxy not responding
```

### 6.3 Check Laravel Logs

```bash
# View Laravel logs
docker exec m3u-editor tail -f /var/www/html/storage/logs/laravel.log

# Look for:
# - "M3U Proxy base URL not configured"
# - "Failed to connect to m3u-proxy"
# - "PUBLIC_URL mismatch detected"
```

### 6.4 Network Debugging

```bash
# Test m3u-proxy from inside container
docker exec m3u-editor curl http://127.0.0.1:8085/health
# Should return JSON with status

# Test m3u-proxy through NGINX
docker exec m3u-editor curl http://127.0.0.1:36400/m3u-proxy/health
# Should return same JSON

# Test from host
curl http://localhost:36400/m3u-proxy/health
# Should return same JSON
```

---

## 7. Summary of Fixes Implemented

### ✅ Already Fixed (Previous Commits)

1. **Profile Matching in Pooling** (Issue #1)

    - Added `profile_id` to stream metadata
    - Prevents quality mismatch in pooled streams

2. **Optimized HLS Profile** (Issue #2)

    - Reduced segment duration: 5s → 2s
    - Increased playlist size: 15 → 30 segments
    - Better delete strategy

3. **Frontend Error Counter** (Issue #3)

    - Resets on successful fragment load
    - Prevents premature fallback

4. **PUBLIC_URL Validation** (Issue #4)
    - Added `validatePublicUrl()` method
    - Updated `m3u-proxy:status` command
    - Detects mismatches between m3u-editor and m3u-proxy

### 🔧 Recommended Additional Fixes

1. **NGINX Headers** (Error #3)

    - Add `X-Forwarded-Host` and `X-Forwarded-Port` headers
    - Helps m3u-proxy detect original request context

2. **HLS GC Threshold** (Error #6)

    - Increase from 3600s to 7200s
    - Reduces risk of segment deletion during playback

3. **Documentation** (All Errors)
    - Add PUBLIC_URL configuration guide
    - Add reverse proxy setup examples
    - Add troubleshooting section

---

## 8. Conclusion

The m3u-editor AIO deployment with embedded m3u-proxy is **well-designed** and **mostly error-free**. The primary source of HLS streaming errors is **PUBLIC_URL misconfiguration**, which is now detectable with the new validation method.

**Key Takeaways:**

1. ✅ **Architecture is sound:** Single-container design works well
2. ⚠️ **PUBLIC_URL is critical:** Must match between m3u-editor and m3u-proxy
3. ✅ **Recent fixes are effective:** Profile pooling and HLS optimization working
4. 📝 **Documentation needed:** Users need guidance on reverse proxy setups

**Next Steps:**

1. Test the new `validatePublicUrl()` method in AIO environment
2. Add NGINX header improvements
3. Create user-facing documentation for common scenarios
4. Consider adding startup validation that warns about misconfigurations

---

**End of Analysis**
