#!/bin/bash

# MediaFlow Microservice Startup Script
# This script installs dependencies and starts the MediaFlow microservice

set -e

echo "🚀 Starting MediaFlow Microservice Setup..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install Node.js 18+ first."
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d 'v' -f 2 | cut -d '.' -f 1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "❌ Node.js version 18+ required. Current version: $(node -v)"
    exit 1
fi

echo "✅ Node.js $(node -v) detected"

# Install npm dependencies if not already installed
if [ ! -d "node_modules" ]; then
    echo "📦 Installing npm dependencies..."
    npm install
else
    echo "✅ Dependencies already installed"
fi

# Check if Laravel is running
LARAVEL_URL="https://m3ueditor.test"
echo "🔍 Checking Laravel connection at $LARAVEL_URL..."

if curl -f -s "$LARAVEL_URL/api/mediaflow/proxy/health" > /dev/null 2>&1; then
    echo "✅ Laravel MediaFlow proxy is accessible"
else
    echo "⚠️  Warning: Cannot connect to Laravel MediaFlow proxy at $LARAVEL_URL"
    echo "   Make sure Laravel is running and MediaFlow proxy is enabled"
fi

# Set environment variables
export MEDIAFLOW_MICROSERVICE_PORT=3001
export LARAVEL_API_URL="https://m3ueditor.test"
export NODE_ENV=${NODE_ENV:-"development"}
export SSL_VERIFY="false"  # Disable SSL verification for self-signed certificates

echo "🔧 Configuration:"
echo "   - Microservice Port: $MEDIAFLOW_MICROSERVICE_PORT"
echo "   - WebSocket Port: $((MEDIAFLOW_MICROSERVICE_PORT + 1))"
echo "   - Laravel API URL: $LARAVEL_API_URL"
echo "   - Environment: $NODE_ENV"
echo "   - SSL Verification: $SSL_VERIFY"

# Start the microservice
echo "🚀 Starting MediaFlow Microservice..."
exec node resources/js/mediaflow-server.js
