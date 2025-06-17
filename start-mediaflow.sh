#!/usr/bin/env bash

# MediaFlow Microservice Startup Script
# This script installs dependencies and starts the MediaFlow microservice

set -e

echo "🚀 Starting MediaFlow Microservice Setup..."

# Install npm dependencies if not already installed
if [ ! -d "node_modules" ]; then
    echo "📦 Installing npm dependencies..."
    npm install
else
    echo "✅ Dependencies already installed"
fi

# Check if Laravel is running
echo "🔍 Checking Laravel connection at $LARAVEL_URL..."

if curl -f -s "$LARAVEL_URL/api/mediaflow/proxy/health" > /dev/null 2>&1; then
    echo "✅ Laravel MediaFlow proxy is accessible"
else
    echo "⚠️  Warning: Cannot connect to Laravel MediaFlow proxy at $LARAVEL_URL"
    echo "   Make sure Laravel is running and MediaFlow proxy is enabled"
fi

# Set environment variables
export MEDIAFLOW_MICROSERVICE_PORT=${MEDIAFLOW_MICROSERVICE_PORT:-3001}
export LARAVEL_API_URL=$LARAVEL_URL
export NODE_ENV=${NODE_ENV:-"development"}
export SSL_VERIFY=${SSL_VERIFY:-"true"}  # Default to true for production, can be overridden

echo "🔧 Configuration:"
echo "   - Microservice Port: $MEDIAFLOW_MICROSERVICE_PORT"
echo "   - WebSocket Port: $((MEDIAFLOW_MICROSERVICE_PORT + 1))"
echo "   - Laravel API URL: $LARAVEL_API_URL"
echo "   - Environment: $NODE_ENV"
echo "   - SSL Verification: $SSL_VERIFY"
echo "   - Environment: $NODE_ENV"

# Start the microservice
echo "🚀 Starting MediaFlow Microservice..."
exec node resources/js/mediaflow-server.js
