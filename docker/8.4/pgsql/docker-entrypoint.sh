#!/usr/bin/env sh
set -e

# Wrapper entrypoint: render postgresql.conf from template (if present)
# and then exec the original postgres entrypoint from the base image.

TEMPLATE="/etc/postgresql/postgresql.conf.tmpl"

if [ -f "$TEMPLATE" ]; then
  echo "Rendering postgresql.conf from template..."
  # Ensure PGDATA exists (the base image sets PGDATA, default /var/lib/postgresql/data)
  : "${PGDATA:=/var/lib/postgresql/data}"
  # Provide defaults for template variables so envsubst produces valid config
  export PG_PORT="${PG_PORT:-5432}"
  export POSTGRES_MAX_CONNECTIONS="${POSTGRES_MAX_CONNECTIONS:-100}"
  export POSTGRES_SHARED_BUFFERS="${POSTGRES_SHARED_BUFFERS:-128MB}"
  mkdir -p "$PGDATA"
  # Render template to the PGDATA directory so postgres will pick it up by default
  envsubst < "$TEMPLATE" > "$PGDATA/postgresql.conf"
  chmod 600 "$PGDATA/postgresql.conf" || true
fi

# Optionally append a pg_hba rule so containers on the same host/network can
# connect during development. This is gated behind environment variables to
# avoid modifying pg_hba by default in production.
# - If PG_HBA_ALLOW_ALL=true, append a permissive 0.0.0.0/0 md5 rule (dev only)
# - If PG_HBA_ALLOW_DOCKER_NETWORK=true, append a 'samenet' rule which matches
#   any address in any subnet the server is directly connected to (no hardcoded IPs)
if [ "${PG_HBA_ALLOW_ALL:-false}" = "true" ] || [ "${PG_HBA_ALLOW_DOCKER_NETWORK:-false}" = "true" ]; then
  HBA_FILE="$PGDATA/pg_hba.conf"
  # Ensure file exists
  touch "$HBA_FILE" || true
  if [ "${PG_HBA_ALLOW_ALL:-false}" = "true" ]; then
    RULE="host    all             all             0.0.0.0/0            md5"
  else
    RULE="host    all             all             samenet            md5"
  fi
  # Append only if rule not present
  if ! grep -Fq "$RULE" "$HBA_FILE" 2>/dev/null; then
    echo "# Added by docker-entrypoint.sh to allow container network access" >> "$HBA_FILE" || true
    echo "$RULE" >> "$HBA_FILE" || true
    echo "Appended pg_hba rule: $RULE"
  else
    echo "pg_hba.conf already contains rule: $RULE"
  fi
fi
# Delegate to the base image's entrypoint (preserve initialization behavior)
exec /usr/local/bin/docker-entrypoint.sh "$@"
