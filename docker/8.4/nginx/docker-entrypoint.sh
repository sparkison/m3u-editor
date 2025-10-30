#!/bin/sh
set -eu

# Variables that may be used in templates. Export defaults so envsubst
# (which reads environment variables) sees them even if not provided by compose.
export NGINX_USER=${NGINX_USER:-nginx}
export APP_PORT=${APP_PORT:-36400}
export APP_HOST=${APP_HOST:-m3u-editor-fpm}
export FPMPORT=${FPMPORT:-9000}
export REVERB_PORT=${REVERB_PORT:-36800}
export PROXY_HOST=${PROXY_HOST:-m3u-proxy}
export PROXY_PORT=${PROXY_PORT:-38085}

TEMPLATE_DIR=/etc/nginx
NGINX_TPL=${TEMPLATE_DIR}/nginx.tmpl
VHOST_TPL=${TEMPLATE_DIR}/conf.d/laravel.tmpl

# Render templates if present
if [ -f "$NGINX_TPL" ]; then
  envsubst '${NGINX_USER}' < "$NGINX_TPL" > /etc/nginx/nginx.conf
fi

if [ -f "$VHOST_TPL" ]; then
  envsubst '${APP_PORT} ${APP_HOST} ${FPMPORT} ${PROXY_PORT} ${REVERB_PORT} ${PROXY_HOST}' < "$VHOST_TPL" > /etc/nginx/conf.d/default.conf
fi

# Create directories expected by nginx
mkdir -p /var/cache/nginx /var/run

# Exec nginx in foreground
exec nginx -g 'daemon off;'
