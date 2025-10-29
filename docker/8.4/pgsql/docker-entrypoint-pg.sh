#!/usr/bin/env sh
set -e

# Wrapper entrypoint: render postgresql.conf from template (if present)
# and then exec the original postgres entrypoint from the base image.

TEMPLATE="/etc/postgresql/postgresql.conf.tmpl"

if [ -f "$TEMPLATE" ]; then
  echo "Rendering postgresql.conf from template..."
  # Ensure PGDATA exists (the base image sets PGDATA, default /var/lib/postgresql/data)
  : "${PGDATA:=/var/lib/postgresql/data}"
  mkdir -p "$PGDATA"
  # Render template to the PGDATA directory so postgres will pick it up by default
  envsubst < "$TEMPLATE" > "$PGDATA/postgresql.conf"
  chmod 600 "$PGDATA/postgresql.conf" || true
fi

# Delegate to the base image's entrypoint (preserve initialization behavior)
exec /usr/local/bin/docker-entrypoint.sh "$@"
