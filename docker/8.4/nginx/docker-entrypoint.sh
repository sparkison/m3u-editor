#!/bin/sh
set -eu

# Variables that may be used in templates
: ${APP_PORT:=36400}
: ${APP_URL:=http://localhost}
: ${FPMPORT:=9000}
: ${M3U_PROXY_PORT:=38085}
: ${REVERB_PORT:=36800}
: ${M3U_PROXY_NGINX_TARGET:=127.0.0.1:38085}

TEMPLATE_DIR=/etc/nginx
NGINX_TPL=${TEMPLATE_DIR}/nginx.tmpl
VHOST_TPL=${TEMPLATE_DIR}/conf.d/laravel.tmpl

# Render templates if present
if [ -f "$NGINX_TPL" ]; then
  envsubst '${APP_PORT} ${APP_URL} ${FPMPORT} ${M3U_PROXY_PORT} ${REVERB_PORT} ${M3U_PROXY_NGINX_TARGET}' < "$NGINX_TPL" > /etc/nginx/nginx.conf
fi

if [ -f "$VHOST_TPL" ]; then
  envsubst '${APP_PORT} ${APP_URL} ${FPMPORT} ${M3U_PROXY_PORT} ${REVERB_PORT} ${M3U_PROXY_NGINX_TARGET}' < "$VHOST_TPL" > /etc/nginx/conf.d/default.conf
fi

# Create directories expected by nginx
mkdir -p /var/cache/nginx /var/run

# Exec nginx in foreground
exec nginx -g 'daemon off;'
