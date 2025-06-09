#!/usr/bin/env bash

# Wait for Redis to be ready
echo "⏳ Waiting for Redis to start..."
while ! redis-cli -p ${REDIS_SERVER_PORT:-36790} ping >/dev/null 2>&1; do
  sleep 1
done

echo "✅ Redis is ready!"

# Navigate to Laravel directory
cd /var/www/html

# 🗑️ Flushing FFmpeg process cache...
php artisan app:flush-ffmpeg-process-cache

# Pruning stale HLS processes...
php artisan app:hls-prune

echo "✅ Startup commands completed successfully!"

# Keep the process alive but in a sleeping state
# This prevents supervisor from restarting it constantly
sleep infinity
