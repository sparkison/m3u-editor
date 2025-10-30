#!/usr/bin/env sh
set -e

# Wrapper entrypoint: render postgresql.conf from template (if present)
# and then exec the original postgres entrypoint from the base image.

TEMPLATE="/etc/postgresql/postgresql.conf.tmpl"
export PG_PORT="${PG_PORT:-54320}"

if [ -f "$TEMPLATE" ]; then
  echo "Rendering postgresql.conf from template..."
  # Ensure PGDATA exists (the base image sets PGDATA, default /var/lib/postgresql/data)
  : "${PGDATA:=/var/lib/postgresql/data}"
  # Provide defaults for template variables so envsubst produces valid config
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
# If the data directory already exists, attempt a safe, temporary start of
# Postgres to ensure the configured DB/user/password are present or updated.
# This mirrors previous behaviour used in the monolith entrypoint and is
if [ -f "${PGDATA:-/var/lib/postgresql/data}/PG_VERSION" ]; then
  echo "PGDATA already initialized — temporarily starting Postgres to validate DB/user..."
  # Ensure PG_PORT is set (use default 54320 if not provided)
  # We export PG_PORT near the top so explicit environment values (from compose)
  # are preserved; fall back to the project default 54320 if unset.
  PG_PORT=${PG_PORT:-54320}
  # Start postgres temporarily as the postgres user, bound to loopback only and on PG_PORT
  su - postgres -c "pg_ctl -D \"${PGDATA}\" -o \"-c listen_addresses='127.0.0.1' -c port=${PG_PORT}\" -w start" || true

  # Helper to run psql as the postgres superuser over TCP (avoid Unix socket)
  run_psql() {
    su - postgres -c "psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -p ${PG_PORT} --username postgres --no-psqlrc -c \"$1\""
  }

  # Ensure database exists
  if [ -n "${POSTGRES_DB:-}" ]; then
    exists=$(su - postgres -c "psql -tA -h 127.0.0.1 -p ${PG_PORT} -U postgres -c \"SELECT 1 FROM pg_database WHERE datname='${POSTGRES_DB}'\"" ) || true
    if [ "${exists}" != "1" ]; then
      echo "Creating database '${POSTGRES_DB}'..."
      run_psql "CREATE DATABASE \"${POSTGRES_DB}\";"
    else
      echo "Database '${POSTGRES_DB}' already exists"
    fi
  fi

  # Ensure role/user exists and has correct password (if provided)
  if [ -n "${POSTGRES_USER:-}" ]; then
    role_exists=$(su - postgres -c "psql -tA -h 127.0.0.1 -p ${PG_PORT} -U postgres -c \"SELECT 1 FROM pg_roles WHERE rolname='${POSTGRES_USER}'\"" ) || true
    if [ "${role_exists}" != "1" ]; then
      echo "Creating role '${POSTGRES_USER}'..."
      run_psql "CREATE ROLE \"${POSTGRES_USER}\" WITH LOGIN PASSWORD '${POSTGRES_PASSWORD:-m3ue}';"
    else
      if [ -n "${POSTGRES_PASSWORD:-}" ]; then
        echo "Altering password for role '${POSTGRES_USER}'..."
        run_psql "ALTER ROLE \"${POSTGRES_USER}\" WITH PASSWORD '${POSTGRES_PASSWORD}';"
      fi
    fi
  fi

  # Stop the temporary server
  echo "Stopping temporary Postgres instance..."
  su - postgres -c "pg_ctl -D \"${PGDATA}\" -m fast stop" || true
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
