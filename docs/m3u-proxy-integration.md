# M3U Proxy Integration Guide

## 📁 Files

**Ready-to-use configuration files:**
- [`docker-compose.full.yml`](../docker-compose.full.yml) - Complete docker-compose setup
- [`docker-compose.full-gluetun.yml`](../docker-compose.full.yml) - Complete docker-compose setup using Gluetun VPN network
- [`.env.example`](../.env.example) - Environment variables template

**Quick deployment:**
```bash
# Download files
curl -O https://raw.githubusercontent.com/sparkison/m3u-editor/main/docker-compose.full.yml
curl -O https://raw.githubusercontent.com/sparkison/m3u-editor/main/.env.example

# Setup environment
cp .env.example .env
# Edit .env and set secure tokens (see instructions in file)

# Deploy
docker-compose -f docker-compose.full.yml up -d
```

## 🚀 Quick Start - Recommended Setup (External Instance)

**Why External?** Running m3u-proxy as a separate container provides better performance, scalability, Redis-based pooling, and independent management. This is the **recommended production setup**.

### Option 1: External Proxy with Redis (Recommended)

Complete docker-compose setup with m3u-editor m3u-proxy:

```yaml
name: m3u-editor
services:
  m3u-editor-nginx:
    build:
      context: .
      dockerfile: Dockerfile
      target: nginx
    image: sparkison/m3u-editor-nginx:${IMAGE_TAG:-latest}
    environment:
      # App reverse proxy setup
      - FPMPORT=${FPMPORT:-9900} # Port that nginx uses to connect to fpm (m3u-editor-fpm)
      - APP_URL=${APP_URL:-http://localhost} # Application URL/IP for accessing on LAN/WAN
      - APP_PORT=${APP_PORT:-36400}
      - APP_HOST=m3u-editor-fpm
      # M3U Proxy reverse proxy setup
      - PROXY_PORT=${M3U_PROXY_PORT:-38085}
      - PROXY_HOST=m3u-proxy
      # Websocket Reverb port for reverse proxying
      - REVERB_PORT=${REVERB_PORT:-36800}
    depends_on:
      m3u-editor-fpm:
        condition: service_healthy
    ports:
      # Expose the main app port
      - '${APP_PORT:-36400}:${APP_PORT:-36400}'
    restart: unless-stopped

  m3u-editor-postgres:
    build:
      context: .
      dockerfile: Dockerfile
      target: postgres
    image: sparkison/m3u-editor-postgres:${IMAGE_TAG:-latest}
    environment:
      PG_HBA_ALLOW_DOCKER_NETWORK: 'true'
      PG_PORT: ${PG_PORT:-54320}
      POSTGRES_DB: ${PG_DATABASE:-m3ue}
      POSTGRES_USER: ${PG_USER:-m3ue}
      POSTGRES_PASSWORD: ${PG_PASSWORD:-m3ue}
      # Ensure PGDATA matches the layout inside the built Postgres image so
      # Docker mounts the named volume to the correct data directory.
      PGDATA: /var/lib/postgresql/17/docker
    volumes:
      # Mount the named volume at the image's PGDATA path (not /var/lib/postgresql/data)
      - m3ue_pgdata:/var/lib/postgresql/17/docker
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -p ${PG_PORT:-54320} -U ${PG_USER:-m3ue} -d ${PG_DATABASE:-m3ue} -h 127.0.0.1 || exit 1"]
      interval: 5s
      timeout: 5s
      retries: 12
    restart: unless-stopped

  m3u-editor-redis:
    build:
      context: .
      dockerfile: Dockerfile
      target: redis
    image: sparkison/m3u-editor-redis:${IMAGE_TAG:-latest}
    environment:
      - REDIS_SERVER_PORT=${REDIS_SERVER_PORT:-36790}
    volumes:
      - m3ue_redis_data:/data
    #ports:
    #  - '${REDIS_SERVER_PORT:-36790}:${REDIS_SERVER_PORT:-36790}'
    healthcheck:
      test: ["CMD", "redis-cli", "-p", "${REDIS_SERVER_PORT:-36790}", "ping"]
      interval: 30s
      timeout: 10s
      retries: 5
    restart: unless-stopped

  m3u-editor-fpm:
    build:
      context: .
      dockerfile: Dockerfile
      target: runtime
      args:
        GIT_BRANCH: ${GIT_BRANCH:-local}
        GIT_COMMIT: ${GIT_COMMIT:-local}
        GIT_TAG: ${GIT_TAG:-local}
    image: sparkison/m3u-editor-fpm:${IMAGE_TAG:-latest}
    environment:
      # App settings
      - APP_ENV=${APP_ENV:-local}
      - APP_DEBUG=${APP_DEBUG:-false}
      - APP_URL=${APP_URL:-http://localhost} # Application URL/IP for accessing on LAN/WAN
      - APP_PORT=${APP_PORT:-36400}
      - FPMPORT=${FPMPORT:-9900}
      # Database settings
      - DB_CONNECTION=pgsql # or sqlite
      - DB_HOST=m3u-editor-postgres
      - DB_PORT=${PG_PORT:-54320}
      - DB_DATABASE=${PG_DATABASE:-m3ue}
      - DB_USERNAME=${PG_USER:-m3ue}
      - DB_PASSWORD=${PG_PASSWORD:-m3ue}
      # Reverb (Websockets) settings
      - REVERB_PORT=${REVERB_PORT:-36800}
      - REDIS_HOST=m3u-editor-redis
      - REDIS_PORT=${REDIS_SERVER_PORT:-36790}
      - REVERB_PORT=${REVERB_PORT:-36800}
      # Proxy settings
      - M3U_PROXY_ENABLED=true
      - M3U_PROXY_TOKEN=${M3U_PROXY_API_TOKEN:-changeme}
      - M3U_PROXY_HOST=${M3U_PROXY_HOST:-m3u-proxy}
      - M3U_PROXY_PORT=${M3U_PROXY_PORT:-38085}
      # Make sure this matches your APP_URL and APP_PORT settings
      # If using HTTPS, include the protocol here (e.g. https://yourdomain.com/m3u-proxy)
      # Default format: <APP_URL>:<APP_PORT>/m3u-proxy
      - M3U_PROXY_PUBLIC_URL=${M3U_PROXY_PUBLIC_URL:-http://localhost:36400/m3u-proxy}
    depends_on:
      m3u-editor-postgres:
        condition: service_healthy
      m3u-editor-redis:
        condition: service_healthy
      m3u-proxy:
        condition: service_healthy
    volumes:
      - ./data:/var/www/config
    #ports:
    #  - '${FPMPORT:-9900}:${FPMPORT:-9900}'
    #  - '${REVERB_PORT:-36800}:${REVERB_PORT:-36800}'
    healthcheck:
      test: ["CMD-SHELL", "bash -c '</dev/tcp/127.0.0.1/${FPMPORT:-9900}' >/dev/null 2>&1"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: unless-stopped

  m3u-proxy:
    image: sparkison/m3u-proxy:${IMAGE_TAG:-latest}
    build:
      context: ../m3u-proxy
      dockerfile: Dockerfile
    environment:
      - PORT=${M3U_PROXY_PORT:-38085}
      - API_TOKEN=${M3U_PROXY_API_TOKEN:-changeme}
      # HLS rewrite URL when using direct proxying (not required for transcoding)
      # Use the NGINX reverse proxy URL so HLS playlists are correctly rewritten
      # <APP_URL>:<APP_PORT>/m3u-proxy
      # If using HTTPS, include the protocol here (e.g. https://yourdomain.com/m3u-proxy)
      - PUBLIC_URL=${M3U_PROXY_PUBLIC_URL:-http://localhost:36400/m3u-proxy}
      - LOG_LEVEL=error # error, warn, info, debug
      - REDIS_ENABLED=true
      - REDIS_HOST=m3u-editor-redis
      - REDIS_SERVER_PORT=${REDIS_SERVER_PORT:-36790}
      - REDIS_DB=6 # Use a separate Redis DB for m3u-proxy caching, 1-5 used by editor
      - ENABLE_TRANSCODING_POOLING=true
    depends_on:
      m3u-editor-redis:
        condition: service_healthy
    #ports:
    #  - '${M3U_PROXY_PORT:-38085}:${M3U_PROXY_PORT:-38085}'
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:${M3U_PROXY_PORT:-38085}/health?api_token=${M3U_PROXY_API_TOKEN:-changeme}"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: unless-stopped

volumes:
  m3ue_pgdata:
  m3ue_redis_data:
```

**Key Benefits:**
- ✅ Redis-based stream pooling (multiple clients share one transcode process)
- ✅ Better performance and resource utilization
- ✅ Independent scaling and management
- ✅ Separate logging and monitoring


## 📋 Management Commands

Note: The table below lists the supported command for checking proxy connectivity from the `m3u-editor` container; management of the proxy service itself should be done via your container orchestration (docker/docker-compose/systemd/etc.).

| Command | Description | When to Use |
|---------|-------------|-------------|
| `php artisan m3u-proxy:status` | Check status, health, and stats (queries the configured proxy endpoint) | Verifying external proxy connectivity and health |

### Command Examples (External Proxy)

```bash
# Check if external proxy is reachable and healthy from the m3u-editor container
docker exec -it m3u-editor php artisan m3u-proxy:status

# Restart external proxy container (do this on the host)
docker restart m3u-proxy

# View external proxy logs
docker logs m3u-proxy -f --tail 100
```


## 🔧 Configuration Reference

### Environment Variables

#### M3U Editor Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `M3U_PROXY_ENABLED` | `false` | `false` = embedded proxy, `true` = external proxy |
| `M3U_PROXY_URL` | auto-set | External: `http://m3u-proxy:8085`, Embedded: `${APP_URL}/m3u-proxy` |
| `M3U_PROXY_TOKEN` | auto-generated | API token - must match `API_TOKEN` in m3u-proxy |

#### M3U Proxy Container Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `API_TOKEN` | none | API authentication token (must match `M3U_PROXY_TOKEN`) |
| `REDIS_URL` | none | Redis connection URL (e.g., `redis://redis:6379/0`) |
| `REDIS_ENABLED` | `false` | Enable Redis connection |
| `ENABLE_TRANSCODING_POOLING` | `true` | Enable transcoding stream pooling (if Redis enabled) |
| `LOG_LEVEL` | `INFO` | Logging level: `DEBUG`, `INFO`, `WARNING`, `ERROR` |
| `ROOT_PATH` | `/m3u-proxy` | API root path - default optimized for m3u-editor integration |
| `DOCS_URL` | `/docs` | Swagger UI path (relative to ROOT_PATH) |

#### Embedded-mode variables (deprecated)

The embedded-mode variables previously used to configure an in-container proxy are deprecated and no longer supported in current images. If you have legacy deployments that reference these variables, migrate to external proxy variables (`M3U_PROXY_ENABLED=true`, `M3U_PROXY_URL`, `M3U_PROXY_TOKEN`).

**Authentication:** When using API token authentication, see [M3U Proxy Authentication](https://github.com/sparkison/m3u-proxy/blob/master/docs/AUTHENTICATION.md)


## 🐛 Troubleshooting

### External Proxy Issues

**Proxy Not Reachable:**
```bash
# Check if m3u-proxy container is running
docker ps | grep m3u-proxy

# Test connectivity from m3u-editor
docker exec -it m3u-editor curl http://m3u-proxy:8085/health

# Check m3u-proxy logs
docker logs m3u-proxy -f --tail 100

# Verify network configuration
docker network inspect m3u-network
```

**Authentication Errors (401/403):**
```bash
# Verify tokens match
docker exec -it m3u-editor env | grep M3U_PROXY_TOKEN
docker exec -it m3u-proxy env | grep API_TOKEN

# Test with token
curl -H "X-API-Token: your-token-here" http://localhost:8085/health
```

**Redis Connection Issues:**
```bash
# Check Redis container
docker ps | grep redis

# Test Redis from m3u-proxy
docker exec -it m3u-proxy ping redis

# Check Redis logs
docker logs m3u-proxy-redis

# Verify Redis URL
docker exec -it m3u-proxy env | grep REDIS_URL
```

<!-- Embedded proxy troubleshooting details removed — embedded proxy is deprecated. -->



## 📍 File Locations

### External Proxy (Container)
| Path | Description |
|------|-------------|
| Container logs | `docker logs m3u-proxy` |
| Redis data | Named volume `redis-data` |

<!-- Embedded proxy installation paths removed (embedded proxy deprecated). -->


## 🔄 Common Workflows

### Deploy New External Setup (Recommended)

1. Create `docker-compose.yml` with the full example above

2. Create `.env` file:
```bash
# Generate secure token
M3U_PROXY_TOKEN=$(openssl rand -hex 32)

# Database credentials
PG_DATABASE=m3ue
PG_USER=m3ue
PG_PASSWORD=$(openssl rand -base64 32)
```

3. Start services:
```bash
docker-compose up -d
```

4. Verify everything is running:
```bash
# Check containers
docker-compose ps

# Test m3u-proxy health
docker exec -it m3u-editor php artisan m3u-proxy:status

# Check Redis connection
docker exec -it m3u-proxy redis-cli -h redis ping
```


## 🧪 Testing

### Test External Proxy

```bash
# 1. Check status from m3u-editor
docker exec -it m3u-editor php artisan m3u-proxy:status

# 2. Test API health directly
curl http://localhost:8085/health

# 3. Test with authentication
curl -H "X-API-Token: your-token-here" http://localhost:8085/health

# 4. List active streams
curl -H "X-API-Token: your-token-here" http://localhost:8085/streams

# 5. Create a test stream
curl -X POST http://localhost:8085/streams \
  -H "X-API-Token: your-token-here" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8"}'

# 6. Test Redis connection
docker exec -it m3u-proxy redis-cli -h redis ping

# 7. Check proxy stats
curl -H "X-API-Token: your-token-here" http://localhost:8085/stats
```

## 🎯 Performance Tuning

### Redis Pooling Configuration

When using external m3u-proxy with Redis, you can tune pooling behavior:

```yaml
m3u-proxy:
  environment:
    - REDIS_URL=redis://redis:6379/0
    - ENABLE_REDIS_POOLING=true
    - REDIS_POOL_MAX_CONNECTIONS=50  # Max Redis connections
    - STREAM_TIMEOUT=300              # Stream timeout in seconds
    - CLEANUP_INTERVAL=60             # Cleanup interval in seconds
```

### Resource Limits

Set resource limits for better control:

```yaml
m3u-proxy:
  deploy:
    resources:
      limits:
        cpus: '2.0'
        memory: 2G
      reservations:
        cpus: '0.5'
        memory: 512M

redis:
  deploy:
    resources:
      limits:
        cpus: '1.0'
        memory: 512M
      reservations:
        cpus: '0.25'
        memory: 128M
```


## 📚 Additional Resources

- **M3U Proxy Repository**: https://github.com/sparkison/m3u-proxy
- **M3U Proxy Documentation**: https://github.com/sparkison/m3u-proxy/tree/master/docs
- **Authentication Guide**: https://github.com/sparkison/m3u-proxy/blob/master/docs/AUTHENTICATION.md
- **Event System**: https://github.com/sparkison/m3u-proxy/blob/master/docs/EVENT_SYSTEM.md

## 💡 Tips & Best Practices

### General
- **Always use API token authentication** in production environments
- **Monitor Redis memory usage** when using pooling (set maxmemory policy)
- **Use docker-compose health checks** for automatic container restart

### External Proxy
- ✅ Redis pooling allows multiple clients to share transcoding processes
- ✅ Independent container restart without affecting m3u-editor
- ✅ Better resource isolation and monitoring
- ✅ Can scale horizontally by running multiple proxy instances
- ✅ Direct access to m3u-proxy API and logs

### Security
- Always set strong `API_TOKEN` / `M3U_PROXY_TOKEN` in production
- Use Docker networks to isolate services
- Don't expose m3u-proxy ports directly (use nginx reverse proxy)
- Rotate tokens periodically
- Monitor logs for unusual activity

### Monitoring
```bash
# Watch proxy logs in real-time (external)
docker logs m3u-proxy -f

# Monitor Redis memory
docker exec -it m3u-proxy-redis redis-cli INFO memory

# Check active streams periodically
watch -n 5 'curl -s -H "X-API-Token: token" http://localhost:8085/stats | jq'
```

## 🔒 Security Recommendations

1. **Use strong API tokens**:
```bash
# Generate secure token
openssl rand -hex 32
```

2. **Limit network exposure**:
```yaml
m3u-proxy:
  ports: []  # Don't expose ports externally
  networks:
    - m3u-network  # Only internal network
```

3. **Use environment files**:
```bash
# .env file (add to .gitignore)
M3U_PROXY_TOKEN=your-secret-token-here
```

4. **Enable Redis authentication** (optional):
```yaml
redis:
  command: redis-server --requirepass your-redis-password
```

5. **Use read-only filesystem** where possible:
```yaml
m3u-proxy:
  read_only: true
  tmpfs:
    - /tmp
```
