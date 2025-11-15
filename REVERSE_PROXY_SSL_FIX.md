# 🔒 REVERSE PROXY + SSL HLS STREAMING FIX

**Date:** 2025-11-15  
**Issue:** HLS streams fail to play when using reverse proxy with domain name and SSL  
**Root Cause:** Mixed content blocking (HTTPS page loading HTTP resources)

---

## 🚨 **CRITICAL ISSUE: Mixed Content Blocking**

### **The Problem**

When m3u-editor is accessed via **HTTPS** (through a reverse proxy with SSL), the HLS playlists contain **HTTP** segment URLs, causing browsers to block them as "mixed content."

**Example:**

1. **User accesses:** `https://your-domain.com/live/user/pass/12345.m3u8`
2. **m3u-editor redirects to:** `https://your-domain.com/m3u-proxy/hls/abc123/playlist.m3u8`
3. **HLS playlist contains:** `http://your-domain.com/m3u-proxy/hls/abc123/segment?url=...` ❌
4. **Browser blocks:** Mixed content (HTTPS page loading HTTP resources)
5. **Result:** Stream fails to play

---

## 🔍 **ROOT CAUSE ANALYSIS**

### **Why This Happens**

**File:** `../m3u-proxy-dev/src/api.py` (lines 679-722)

**Current Code (BEFORE FIX):**

```python
# Line 681-689
public_url = getattr(settings, 'PUBLIC_URL', None)
if public_url:
    public_with_scheme = public_url if public_url.startswith(('http://', 'https://')) else f"http://{public_url}"
    parsed = urlparse(public_with_scheme)
    scheme = parsed.scheme or 'http'  # ❌ Only uses PUBLIC_URL, ignores X-Forwarded-Proto!
```

**The Flow:**

1. **Reverse Proxy (Caddy/NGINX):**

    - Terminates SSL/TLS
    - Forwards request to m3u-proxy with `X-Forwarded-Proto: https`
    - m3u-proxy receives plain HTTP request internally

2. **m3u-proxy:**

    - Reads `PUBLIC_URL` environment variable: `http://your-domain.com/m3u-proxy`
    - Extracts scheme: `http://` (from PUBLIC_URL)
    - **Ignores** `X-Forwarded-Proto: https` header
    - Constructs HLS segment URLs with `http://` scheme

3. **Browser:**
    - Receives HLS playlist with `http://` segment URLs
    - Blocks mixed content (HTTPS page loading HTTP resources)
    - Stream fails to play

---

## ✅ **THE FIX**

### **Respect X-Forwarded-Proto Header**

**File:** `../m3u-proxy-dev/src/api.py`  
**Lines:** 679-727

**New Code (AFTER FIX):**

```python
# Line 681-696
public_url = getattr(settings, 'PUBLIC_URL', None)
if public_url:
    public_with_scheme = public_url if public_url.startswith(('http://', 'https://')) else f"http://{public_url}"
    parsed = urlparse(public_with_scheme)
    scheme = parsed.scheme or 'http'

    # ✅ FIX: Respect X-Forwarded-Proto header from reverse proxy (for SSL/TLS termination)
    # This allows m3u-proxy to detect HTTPS when behind a reverse proxy with SSL
    forwarded_proto = request.headers.get("x-forwarded-proto")
    if forwarded_proto and forwarded_proto.lower() in ('http', 'https'):
        scheme = forwarded_proto.lower()
        logger.debug(f"Using X-Forwarded-Proto: {scheme} for HLS playlist URLs")
```

**What Changed:**

1. ✅ **Read `X-Forwarded-Proto` header** from reverse proxy
2. ✅ **Override scheme** if header is present and valid
3. ✅ **Construct HLS URLs** with correct scheme (https://)
4. ✅ **Log the override** for debugging

---

## 🔧 **CONFIGURATION REQUIREMENTS**

### **1. Reverse Proxy Must Send X-Forwarded-Proto Header**

**Caddy (Caddyfile):**

```caddyfile
reverse_proxy m3u-proxy:38085 {
    header_up X-Forwarded-Proto {scheme}  # ✅ Already configured
    header_up X-Forwarded-Host {host}
    header_up X-Forwarded-For {remote_host}
}
```

**NGINX (nginx.conf):**

```nginx
location ~ ^/m3u-proxy/ {
    proxy_pass http://m3u-proxy;
    proxy_set_header X-Forwarded-Proto $scheme;  # ✅ Add this
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

---

### **2. PUBLIC_URL Environment Variable**

**Option A: Use HTTP in PUBLIC_URL (Recommended)**

Let the reverse proxy header override the scheme:

```bash
# .env or docker-compose.yml
M3U_PROXY_PUBLIC_URL=http://your-domain.com/m3u-proxy
```

**How it works:**

-   m3u-proxy reads `http://` from PUBLIC_URL
-   Detects `X-Forwarded-Proto: https` header
-   Overrides scheme to `https://`
-   Constructs URLs: `https://your-domain.com/m3u-proxy/hls/.../segment`

---

**Option B: Use HTTPS in PUBLIC_URL (Also Works)**

Explicitly set HTTPS in PUBLIC_URL:

```bash
# .env or docker-compose.yml
M3U_PROXY_PUBLIC_URL=https://your-domain.com/m3u-proxy
```

**How it works:**

-   m3u-proxy reads `https://` from PUBLIC_URL
-   Uses HTTPS scheme directly
-   No header override needed

---

### **3. APP_URL in m3u-editor**

Make sure m3u-editor also uses HTTPS:

```bash
# .env
APP_URL=https://your-domain.com
```

This ensures:

-   Laravel generates HTTPS URLs
-   CSRF tokens work correctly
-   Asset URLs use HTTPS

---

## 📊 **TESTING THE FIX**

### **1. Rebuild m3u-proxy Container**

```bash
# SSH into Unraid server
cd /path/to/m3u-editor-dev

# Pull latest m3u-proxy changes
cd ../m3u-proxy-dev
git pull origin dev

# Rebuild m3u-editor container (which includes m3u-proxy)
cd ../m3u-editor-dev
docker-compose down
docker-compose build --no-cache m3u-editor
docker-compose up -d
```

---

### **2. Verify Configuration**

```bash
# Check m3u-proxy logs for X-Forwarded-Proto detection
docker logs m3ua-hektic -f --tail 100 | grep "X-Forwarded-Proto"

# Should see:
# Using X-Forwarded-Proto: https for HLS playlist URLs
```

---

### **3. Test HLS Playlist**

**Access a channel:**

```
https://your-domain.com/live/user/pass/12345.m3u8
```

**Check playlist content:**

```bash
curl -s "https://your-domain.com/m3u-proxy/hls/abc123/playlist.m3u8" | grep "segment"
```

**Expected output:**

```
https://your-domain.com/m3u-proxy/hls/abc123/segment?url=...  # ✅ HTTPS!
```

**NOT:**

```
http://your-domain.com/m3u-proxy/hls/abc123/segment?url=...   # ❌ HTTP (old behavior)
```

---

### **4. Browser Console Check**

**Open browser console (F12) and check for:**

**BEFORE FIX:**

```
Mixed Content: The page at 'https://your-domain.com/...' was loaded over HTTPS,
but requested an insecure resource 'http://your-domain.com/m3u-proxy/hls/.../segment'.
This request has been blocked; the content must be served over HTTPS.
```

**AFTER FIX:**

```
✅ No mixed content errors
✅ Stream plays successfully
```

---

## 🎯 **SUMMARY**

### **What Was Fixed**

| Component         | Issue                                               | Fix                                          |
| ----------------- | --------------------------------------------------- | -------------------------------------------- |
| **m3u-proxy**     | Ignored `X-Forwarded-Proto` header                  | Now respects header and overrides scheme     |
| **HLS Playlists** | Contained HTTP URLs when accessed via HTTPS         | Now generates HTTPS URLs when header present |
| **Browser**       | Blocked mixed content (HTTPS page + HTTP resources) | No more mixed content errors                 |

### **Files Changed**

| File                          | Lines   | Change                                   |
| ----------------------------- | ------- | ---------------------------------------- |
| `../m3u-proxy-dev/src/api.py` | 690-696 | Added X-Forwarded-Proto header detection |

### **Configuration Required**

| Setting                | Value                              | Purpose                                        |
| ---------------------- | ---------------------------------- | ---------------------------------------------- |
| `M3U_PROXY_PUBLIC_URL` | `http://your-domain.com/m3u-proxy` | Base URL (scheme will be overridden by header) |
| `APP_URL`              | `https://your-domain.com`          | Laravel HTTPS URL generation                   |
| Reverse Proxy          | `X-Forwarded-Proto: https`         | Tell m3u-proxy to use HTTPS                    |

---

## 🔍 **ADDITIONAL DEBUGGING**

### **Check Reverse Proxy Headers**

**Test if reverse proxy is sending headers:**

```bash
# From inside m3u-editor container
docker exec -it m3ua-hektic bash

# Install curl if not present
apk add curl

# Test request to m3u-proxy
curl -H "X-Forwarded-Proto: https" http://127.0.0.1:8085/health
```

---

### **Check m3u-proxy Logs**

**Enable DEBUG logging:**

```bash
# .env or docker-compose.yml
M3U_PROXY_LOG_LEVEL=DEBUG
```

**Restart container:**

```bash
docker restart m3ua-hektic
```

**Watch logs:**

```bash
docker logs m3ua-hektic -f --tail 100 | grep -E "X-Forwarded|scheme|https"
```

**Expected output:**

```
Using X-Forwarded-Proto: https for HLS playlist URLs
```

---

### **Verify Reverse Proxy Configuration**

**Caddy:**

```bash
# Check if Caddyfile has X-Forwarded-Proto
docker exec -it caddy cat /etc/caddy/Caddyfile | grep -A 5 "m3u-proxy"
```

**NGINX:**

```bash
# Check if nginx.conf has X-Forwarded-Proto
docker exec -it m3ua-hektic cat /etc/nginx/nginx.conf | grep -A 10 "m3u-proxy"
```

---

## 🚀 **PERFORMANCE IMPACT**

**Overhead:** Negligible

-   Single header read per playlist request
-   No additional network calls
-   No performance degradation

**Benefits:**

-   ✅ Streams work with HTTPS
-   ✅ No mixed content warnings
-   ✅ Better security (all traffic encrypted)
-   ✅ Compatible with modern browsers

---

## 📝 **RELATED ISSUES**

### **Issue #1: Port in URLs**

**Problem:** URLs include port when using standard HTTPS port (443)

**Example:**

```
https://your-domain.com:443/m3u-proxy/hls/.../segment  # ❌ Unnecessary port
```

**Fix:** Remove port from PUBLIC_URL if using standard ports:

```bash
# CORRECT:
M3U_PROXY_PUBLIC_URL=https://your-domain.com/m3u-proxy

# INCORRECT:
M3U_PROXY_PUBLIC_URL=https://your-domain.com:443/m3u-proxy
```

---

### **Issue #2: Double /m3u-proxy Prefix**

**Problem:** URLs have duplicate `/m3u-proxy` prefix

**Example:**

```
https://your-domain.com/m3u-proxy/m3u-proxy/hls/.../segment  # ❌ Duplicate
```

**Fix:** Ensure PUBLIC_URL includes `/m3u-proxy` only once:

```bash
# CORRECT:
M3U_PROXY_PUBLIC_URL=https://your-domain.com/m3u-proxy

# INCORRECT:
M3U_PROXY_PUBLIC_URL=https://your-domain.com
ROOT_PATH=/m3u-proxy  # This will cause duplication
```

**Note:** `ROOT_PATH=/m3u-proxy` is the default and is automatically added by m3u-proxy. Don't include it in PUBLIC_URL.

---

### **Issue #3: Localhost in URLs**

**Problem:** URLs contain `localhost` instead of actual domain

**Example:**

```
https://localhost/m3u-proxy/hls/.../segment  # ❌ Won't work externally
```

**Fix:** Set PUBLIC_URL to your actual domain:

```bash
# CORRECT:
M3U_PROXY_PUBLIC_URL=https://your-domain.com/m3u-proxy

# INCORRECT:
M3U_PROXY_PUBLIC_URL=https://localhost/m3u-proxy
```

---

## 🎓 **HOW IT WORKS**

### **Request Flow with SSL**

```
1. Client Browser
   ↓ HTTPS Request
   GET https://your-domain.com/live/user/pass/12345.m3u8

2. Reverse Proxy (Caddy/NGINX)
   ↓ SSL Termination
   ↓ Add Headers: X-Forwarded-Proto: https
   ↓ Forward to m3u-proxy
   GET http://127.0.0.1:8085/hls/abc123/playlist.m3u8

3. m3u-proxy
   ↓ Read X-Forwarded-Proto header
   ↓ Override scheme: http → https
   ↓ Construct segment URLs with https://
   ↓ Return playlist

4. Client Browser
   ↓ Receive playlist with HTTPS URLs
   ↓ Request segments
   GET https://your-domain.com/m3u-proxy/hls/abc123/segment?url=...
   ✅ No mixed content error!
```

---

### **Code Flow**

```python
# api.py line 679-727

# 1. Read PUBLIC_URL from environment
public_url = "http://your-domain.com/m3u-proxy"

# 2. Parse scheme from PUBLIC_URL
scheme = "http"  # Initial value

# 3. Check X-Forwarded-Proto header
forwarded_proto = request.headers.get("x-forwarded-proto")  # "https"

# 4. Override scheme if header present
if forwarded_proto == "https":
    scheme = "https"  # ✅ Override!

# 5. Construct base URL
base = f"{scheme}://{host}{path}"  # "https://your-domain.com/m3u-proxy"

# 6. Construct segment URLs
segment_url = f"{base}/hls/{stream_id}/segment?url=..."
# Result: "https://your-domain.com/m3u-proxy/hls/abc123/segment?url=..."
```

---

## 📚 **REFERENCES**

-   **X-Forwarded-Proto:** https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-Proto
-   **Mixed Content:** https://developer.mozilla.org/en-US/docs/Web/Security/Mixed_content
-   **HLS Specification:** https://datatracker.ietf.org/doc/html/rfc8216

---

**End of Document**
