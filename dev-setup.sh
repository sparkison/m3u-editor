#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# Check if Docker is installed
echo "Checking for Docker..."
if ! [ -x "$(command -v docker)" ]; then
  echo "Docker is not installed. Please install Docker Desktop from https://www.docker.com/products/docker-desktop and try again."
  exit 1
fi

echo "Docker is installed."

# Function to setup .env file with development configuration
setup_env_file() {
  echo "Setting up environment file..."
  if [ ! -f .env ]; then
    cp .env.example .env

    # Configure application environment
    set_env "APP_ENV" "local"
    set_env "APP_DEBUG" "true"
    set_env "LOG_LEVEL" "debug"
    set_env "APP_URL" "http://localhost:3000"
    set_env "APP_PORT" "3000"

    # Configure database and queue settings
    set_env "DB_CONNECTION" "sqlite"
    set_env "QUEUE_CONNECTION" "redis"
    set_env "SESSION_DRIVER" "redis"
    set_env "CACHE_STORE" "redis"

    # Configure broadcasting, cache, and session settings
    set_env "BROADCAST_CONNECTION" "log"

    # Configure Redis settings
    set_env "REDIS_HOST" "redis"
    set_env "REDIS_PORT" "6379"
    set_env "REDIS_SERVER_PORT" "6379"

    echo "Environment file configured for development."
  else
    echo ".env file already exists. Skipping environment variable setup."
  fi
}

# Function to clean up existing setup
clean_setup() {
  echo "Performing clean setup..."

  # Stop and remove existing containers
  if [ -f "vendor/bin/sail" ]; then
    echo "Stopping and removing existing Sail containers..."
    ./vendor/bin/sail down -v || true
  fi

  # Remove docker-compose.yml if it exists
  if [ -f "docker-compose.yml" ]; then
    echo "Removing existing docker-compose.yml..."
    rm docker-compose.yml
  fi

  # Recreate .env file
  setup_env_file
}

# Install Laravel Sail dependencies
install_sail() {
  echo "Installing Laravel Sail dependencies..."
  if [ ! -f "vendor/bin/sail" ]; then
    composer install
  fi
  ./artisan sail:install --no-interaction --with=redis
}

# Start Laravel Sail containers
start_containers() {
  echo "Starting Laravel Sail containers..."
  ./vendor/bin/sail up -d

  echo "Waiting for Laravel container to be ready..."
  until ./vendor/bin/sail exec laravel.test php artisan --version > /dev/null 2>&1; do
    echo -n "."
    sleep 1
  done
  echo "\nLaravel container is ready!"
}

# Function to set or update an environment variable in the .env file
set_env() {
  local key="$1"
  local value="$2"

  # Escape special characters in the value
  value=$(echo "$value" | sed 's/[&/]/\\&/g')

  if grep -q "^${key}=" .env; then
    sed "s/^${key}=.*/${key}=${value}/" .env > .env.tmp && mv .env.tmp .env
  else
    echo "${key}=${value}" >> .env
  fi
}

# Main script execution
if [[ "$1" == "clean" ]]; then
  clean_setup
else
  if [ ! -f ".env" ]; then
    setup_env_file
  fi
fi

install_sail
start_containers

# Generate application key
./vendor/bin/sail artisan key:generate

# Generate new Reverb keys on clean install
if [[ "$1" == "clean" ]]; then
  echo "Generating new Reverb keys using openssl..."
  REVERB_APP_KEY=$(./vendor/bin/sail exec laravel.test openssl rand -hex 16 | tr -d '\r\n')
  REVERB_APP_SECRET=$(./vendor/bin/sail exec laravel.test openssl rand -hex 32 | tr -d '\r\n')
  set_env "REVERB_APP_KEY" "$REVERB_APP_KEY"
  set_env "REVERB_APP_SECRET" "$REVERB_APP_SECRET"
fi

# Test Redis connection
echo "Testing Redis connection..."
./vendor/bin/sail exec redis redis-cli ping || echo "Redis service not responding, but continuing setup..."

# Finalize Laravel setup
if [[ "$1" == "clean" || ! -f "database/database.sqlite" || ! -f "database/jobs.sqlite" ]]; then
    if [[ "$1" == "clean" ]]; then
        echo "Clean setup specified. Deleting SQLite database files and re-seeding..."
        rm -f database/database.sqlite database/jobs.sqlite
    else
        echo "SQLite database files not found. Creating them and seeding the database..."
    fi
    touch database/database.sqlite database/jobs.sqlite
    ./vendor/bin/sail artisan migrate:fresh --seed --force
else
  echo "SQLite database files exist. Running migrations..."
  ./vendor/bin/sail artisan migrate
fi

# Start Horizon queue worker in the background (inside the container)
echo "Starting Horizon queue worker..."
./vendor/bin/sail exec -d laravel.test php artisan horizon

# Install Node.js dependencies
echo "Installing Node.js dependencies..."
./vendor/bin/sail npm install

# Build frontend assets
echo "Building frontend assets..."
./vendor/bin/sail npm run dev

echo "Development environment setup is complete."