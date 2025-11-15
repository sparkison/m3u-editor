# Universal Reverse Proxy HTTPS Detection Fix

**Date:** 2025-11-14  
**Issue:** HLS streams showing black screen when using reverse proxy with SSL/HTTPS  
**Root Cause:** m3u-proxy generating HTTP URLs instead of HTTPS URLs, causing mixed content blocking  
**Solution:** Universal HTTPS detection from multiple reverse proxy headers

---

## 🎯 **THE PROBLEM**

When accessing m3u-editor via HTTPS through a reverse proxy (NGINX Proxy Manager, Caddy, Traefik, etc.), HLS streams would show a **black screen with spinning icon**.

### **What Was Happening:**

1. ✅ User accesses m3u-editor via HTTPS: `https://m3uhek.urztn.com`
2. ✅ User clicks play on a channel
3. ✅ m3u-proxy creates stream and serves HLS playlist
4. ❌ **Playlist contains HTTP URLs instead of HTTPS URLs**
5. ❌ Browser blocks HTTP requests on HTTPS page (mixed content blocking)
6. ❌ Player shows black screen with spinning icon

### **Example of the Problem:**

**Browser Console Error:**
```
Mixed Content: The page at 'https://m3uhek.urztn.com' was loaded over HTTPS, 
but requested an insecure resource 'http://m3uhek.urztn.com:38389/m3u-proxy/hls/...'. 
This request has been blocked; the content must be served over HTTPS.
```

**Network Tab:**
```
Request URL: http://m3uhek.urztn.com:38389/m3u-proxy/hls/2355f300608e673417cae9f7ad850411/segment?url=...
                ^^^^
                Should be HTTPS!
```

---

## ✅ **THE SOLUTION**

Implemented **universal HTTPS detection** that works with **ALL major reverse proxies** without requiring user configuration.

### **What Was Changed:**

**Repository:** `m3u-proxy`  
**Branch:** `dev`  
**Commit:** `fd1895c`  
**File:** `src/api.py`

### **New Features:**

1. ✅ **Universal HTTPS Detection Function** (`detect_https_from_headers()`)
   - Checks multiple reverse proxy headers
   - Works with all major reverse proxies
   - No user configuration required

2. ✅ **Supported Reverse Proxies:**
   - NGINX, NGINX Proxy Manager (X-Forwarded-Proto)
   - Caddy (X-Forwarded-Proto)
   - Traefik (X-Forwarded-Proto)
   - Apache (X-Forwarded-Proto)
   - Cloudflare, AWS ELB (X-Forwarded-Ssl)
   - Microsoft IIS, Azure (Front-End-Https)
   - RFC 7239 compliant proxies (Forwarded header)
   - Tailscale, Headscale, Netbird, Pangolin (X-Forwarded-Port)
   - **Any reverse proxy that sets standard headers**

3. ✅ **Detection Priority:**
   1. `X-Forwarded-Proto: https` (most common)
   2. `X-Forwarded-Ssl: on` (Cloudflare)
   3. `Front-End-Https: on` (Microsoft IIS)
   4. `Forwarded: proto=https` (RFC 7239)
   5. `X-Forwarded-Port: 443` (port-based detection)

---

## 📝 **TECHNICAL DETAILS**

### **Code Changes:**

**Added Helper Function:**
```python
def detect_https_from_headers(request: Request) -> bool:
    """
    Universal HTTPS detection from reverse proxy headers.
    Works with all major reverse proxies without user configuration.
    """
    # Check X-Forwarded-Proto (NGINX, Caddy, Traefik, NPM)
    if request.headers.get("x-forwarded-proto") == "https":
        return True
    
    # Check X-Forwarded-Ssl (Cloudflare, AWS ELB)
    if request.headers.get("x-forwarded-ssl") == "on":
        return True
    
    # Check Front-End-Https (Microsoft IIS, Azure)
    if request.headers.get("front-end-https") == "on":
        return True
    
    # Check Forwarded header (RFC 7239 standard)
    forwarded = request.headers.get("forwarded")
    if forwarded and "proto=https" in forwarded.lower():
        return True
    
    # Check X-Forwarded-Port (if 443, assume HTTPS)
    if request.headers.get("x-forwarded-port") == "443":
        return True
    
    return False
```

**Updated HLS Playlist Endpoint:**
```python
# Old code (only checked X-Forwarded-Proto)
forwarded_proto = request.headers.get("x-forwarded-proto")
if forwarded_proto and forwarded_proto.lower() in ('http', 'https'):
    scheme = forwarded_proto.lower()

# New code (universal detection)
if detect_https_from_headers(request):
    scheme = "https"
```

---

## 🚀 **HOW TO APPLY THE FIX**

### **Step 1: Pull Latest Changes**

```bash
cd /path/to/m3u-proxy-dev
git pull origin dev
```

### **Step 2: Rebuild Container**

```bash
docker-compose down
docker-compose build --no-cache m3u-proxy
docker-compose up -d
```

### **Step 3: Test**

1. Access m3u-editor via HTTPS
2. Play a channel
3. Check browser console - should see no mixed content errors
4. Check Network tab - URLs should be HTTPS

---

## 🎉 **EXPECTED RESULT**

After applying this fix:

✅ HLS streams play correctly with HTTPS reverse proxy  
✅ No mixed content blocking errors  
✅ No black screen with spinning icon  
✅ Works with ALL major reverse proxies  
✅ No user configuration required  
✅ Auto-detects HTTPS from standard headers  

---

## 📊 **TESTING CHECKLIST**

- [ ] Pull latest m3u-proxy code from dev branch
- [ ] Rebuild m3u-proxy container
- [ ] Access m3u-editor via HTTPS
- [ ] Play a live channel
- [ ] Verify no mixed content errors in browser console
- [ ] Verify segment URLs use HTTPS in Network tab
- [ ] Test with external player (VLC, IPTV Smarters, etc.)

---

## 🔍 **TROUBLESHOOTING**

### **If streams still don't play:**

1. **Check reverse proxy is sending headers:**
   - Look for `X-Forwarded-Proto: https` in m3u-proxy logs
   - Enable debug logging: `LOG_LEVEL=DEBUG`

2. **Check PUBLIC_URL is set correctly:**
   - Should match your public domain
   - Example: `PUBLIC_URL=https://m3uhek.urztn.com/m3u-proxy`

3. **Check reverse proxy configuration:**
   - Ensure SSL termination is enabled
   - Ensure headers are being forwarded

---

## 📚 **RELATED COMMITS**

- `a190413` - Initial X-Forwarded-Proto support
- `fd1895c` - Universal HTTPS detection (this fix)

---

## 🙏 **ACKNOWLEDGMENTS**

This fix was implemented to support the thousands of m3u-editor users who use different reverse proxy solutions. No user configuration is required - the application now auto-detects HTTPS from standard reverse proxy headers.

