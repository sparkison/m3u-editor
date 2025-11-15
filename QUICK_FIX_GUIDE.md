# Quick Fix Guide - HLS Black Screen with Reverse Proxy + SSL

## 🚨 **PROBLEM**

Black screen with spinning icon when playing HLS streams through HTTPS reverse proxy.

## ✅ **SOLUTION**

Two-part fix has been implemented:

1. **m3u-proxy:** Universal HTTPS detection (commit `fd1895c`)
2. **m3u-editor:** Preserve X-Forwarded-Proto from upstream (commit `939b6830`)

---

## 📋 **APPLY THE FIX (5 MINUTES)**

### **1. Update m3u-editor Code**

```bash
# SSH into your server
ssh root@your-server

# Navigate to m3u-editor directory
cd /path/to/m3u-editor-dev

# Pull latest changes
git pull origin dev

# You should see:
# Updating XXXXXXX..939b6830
# Fast-forward
#  nginx.conf | XX insertions(+), XX deletions(-)
#  Caddyfile  | XX insertions(+), XX deletions(-)
```

### **2. Update m3u-proxy Code**

```bash
# Navigate to m3u-proxy directory
cd /path/to/m3u-proxy-dev

# Pull latest changes
git pull origin dev

# You should see:
# Updating a190413..fd1895c
# Fast-forward
#  src/api.py | 54 ++++++++++++++++++++++++++++++++++++++++++++++++------
#  1 file changed, 48 insertions(+), 6 deletions(-)
```

### **3. Rebuild Containers**

```bash
# Go back to m3u-editor directory
cd /path/to/m3u-editor-dev

# Stop all containers
docker-compose down

# Rebuild with no cache
docker-compose build --no-cache

# Start containers
docker-compose up -d
```

### **4. Test**

1. Open browser and go to `https://m3uhek.urztn.com` (your domain)
2. Click on a channel to play
3. Stream should play without black screen
4. Check browser console (F12) - should see no mixed content errors

---

## 🔍 **VERIFY THE FIX**

### **Check Browser Console (F12 → Console)**

**Before Fix:**

```
❌ Mixed Content: The page at 'https://...' was loaded over HTTPS,
   but requested an insecure resource 'http://...'.
   This request has been blocked.
```

**After Fix:**

```
✅ No mixed content errors
✅ Stream plays normally
```

### **Check Network Tab (F12 → Network)**

**Before Fix:**

```
❌ Request URL: http://m3uhek.urztn.com:38389/m3u-proxy/hls/...
                ^^^^
```

**After Fix:**

```
✅ Request URL: https://m3uhek.urztn.com:38389/m3u-proxy/hls/...
                ^^^^^
```

---

## 🎯 **WHAT WAS FIXED**

### **Root Cause:**

The **internal NGINX/Caddy** was **OVERWRITING** the `X-Forwarded-Proto` header from your reverse proxy (NGINX Proxy Manager).

### **The Fix (Two Parts):**

**Part 1: m3u-proxy (commit `fd1895c`)**

-   Added universal HTTPS detection
-   Checks 5 different header types
-   Works with all reverse proxies

**Part 2: m3u-editor (commit `939b6830`) - THE CRITICAL FIX**

-   ✅ **NGINX:** Preserve `X-Forwarded-Proto` from upstream instead of overwriting
-   ✅ **Caddy:** Preserve `X-Forwarded-Proto` from upstream instead of overwriting
-   ✅ Also forward `X-Forwarded-Ssl` and `X-Forwarded-Port` headers

**No configuration required** - it just works!

---

## 🛠️ **TROUBLESHOOTING**

### **If streams still don't play after applying fix:**

#### **1. Check m3u-proxy logs:**

```bash
docker logs m3ua-hektic | grep -i "detected https"
```

**Expected output:**

```
Detected HTTPS via X-Forwarded-Proto: https
```

**If you see:**

```
Using X-Forwarded-Proto: http for HLS playlist URLs
```

Then your reverse proxy is not sending the correct header. Check your NPM configuration.

#### **2. Check environment variables:**

```bash
docker exec m3ua-hektic env | grep -E "PUBLIC_URL|STREAM_TIMEOUT"
```

**Expected:**

```
PUBLIC_URL=https://m3uhek.urztn.com/m3u-proxy
STREAM_TIMEOUT=60
```

**If STREAM_TIMEOUT=0:**

```bash
# Add to your .env file or docker-compose.yml
STREAM_TIMEOUT=60
```

#### **3. Check NGINX Proxy Manager:**

Make sure your proxy host has:

-   ✅ SSL certificate enabled
-   ✅ Force SSL enabled
-   ✅ WebSocket support enabled (if available)

---

## 📞 **STILL HAVING ISSUES?**

If the fix doesn't work, provide:

1. **m3u-proxy logs:**

    ```bash
    docker logs m3ua-hektic --tail 100
    ```

2. **Browser console errors:**

    - Press F12 → Console tab
    - Copy any red errors

3. **Network tab:**

    - Press F12 → Network tab
    - Filter by "m3u-proxy"
    - Copy the Request URL of failed requests

4. **Environment variables:**
    ```bash
    docker exec m3ua-hektic env | grep -E "PUBLIC_URL|STREAM_TIMEOUT|ROOT_PATH"
    ```

---

## ✅ **SUCCESS CRITERIA**

After applying the fix, you should see:

-   ✅ Streams play without black screen
-   ✅ No mixed content errors in browser console
-   ✅ All segment URLs use HTTPS (check Network tab)
-   ✅ Works with external players (VLC, IPTV Smarters, etc.)
-   ✅ Works on mobile devices

---

## 🎉 **DONE!**

Your HLS streams should now work perfectly with HTTPS reverse proxy!

**Commit:** `fd1895c`  
**Repository:** m3u-proxy  
**Branch:** dev
