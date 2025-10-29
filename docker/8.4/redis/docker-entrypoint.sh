#!/bin/sh
set -eu

export REDIS_SERVER_PORT="${REDIS_SERVER_PORT:-6379}"

TEMPLATE=/etc/redis/redis.tmpl
CONF=/etc/redis/redis.conf

if [ -f "$TEMPLATE" ]; then
  envsubst '${REDIS_SERVER_PORT}' < "$TEMPLATE" > "$CONF"
fi

exec redis-server "$CONF"
