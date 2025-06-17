#!/bin/bash

# MediaFlow Proxy Test Script
# Tests all major endpoints to ensure everything is working

echo "🧪 Testing MediaFlow Proxy Implementation..."

# Test Laravel MediaFlow proxy health endpoint
echo ""
echo "1️⃣ Testing Laravel MediaFlow Proxy Health..."
LARAVEL_RESPONSE=$(curl -s -w "%{http_code}" https://m3ueditor.test/api/mediaflow/proxy/health -k)
LARAVEL_CODE=${LARAVEL_RESPONSE: -3}
if [ "$LARAVEL_CODE" = "200" ]; then
    echo "✅ Laravel MediaFlow proxy is responding"
else
    echo "❌ Laravel MediaFlow proxy failed (HTTP $LARAVEL_CODE)"
fi

# Test Laravel MediaFlow proxy IP endpoint
echo ""
echo "2️⃣ Testing Laravel MediaFlow Proxy IP endpoint..."
IP_RESPONSE=$(curl -s -w "%{http_code}" https://m3ueditor.test/api/mediaflow/proxy/ip -k)
IP_CODE=${IP_RESPONSE: -3}
if [ "$IP_CODE" = "200" ]; then
    echo "✅ Laravel MediaFlow IP endpoint is responding"
    echo "   Response: ${IP_RESPONSE%???}"
else
    echo "❌ Laravel MediaFlow IP endpoint failed (HTTP $IP_CODE)"
fi

# Test JavaScript Microservice health endpoint
echo ""
echo "3️⃣ Testing JavaScript Microservice Health..."
MICRO_RESPONSE=$(curl -s -w "%{http_code}" http://localhost:3001/health)
MICRO_CODE=${MICRO_RESPONSE: -3}
if [ "$MICRO_CODE" = "200" ]; then
    echo "✅ JavaScript Microservice is responding"
    echo "   $(echo "${MICRO_RESPONSE%???}" | jq -r '.service + " v" + .version')"
else
    echo "❌ JavaScript Microservice failed (HTTP $MICRO_CODE)"
fi

# Test WebSocket server
echo ""
echo "4️⃣ Testing WebSocket Server..."
if nc -z localhost 3002 2>/dev/null; then
    echo "✅ WebSocket server is listening on port 3002"
else
    echo "❌ WebSocket server is not accessible on port 3002"
fi

# Test Laravel admin interface
echo ""
echo "5️⃣ Testing Laravel Admin Interface..."
ADMIN_RESPONSE=$(curl -s -w "%{http_code}" https://m3ueditor.test/media-flow-proxy-management -k)
ADMIN_CODE=${ADMIN_RESPONSE: -3}
if [ "$ADMIN_CODE" = "200" ] || [ "$ADMIN_CODE" = "302" ]; then
    echo "✅ Laravel Admin interface is accessible"
else
    echo "❌ Laravel Admin interface failed (HTTP $ADMIN_CODE)"
fi

echo ""
echo "🎉 MediaFlow Proxy test completed!"
echo ""
echo "📋 Summary:"
echo "   - Laravel Proxy: $([ "$LARAVEL_CODE" = "200" ] && echo "✅ Working" || echo "❌ Failed")"
echo "   - IP Endpoint: $([ "$IP_CODE" = "200" ] && echo "✅ Working" || echo "❌ Failed")"
echo "   - Microservice: $([ "$MICRO_CODE" = "200" ] && echo "✅ Working" || echo "❌ Failed")"
echo "   - WebSocket: $(nc -z localhost 3002 2>/dev/null && echo "✅ Working" || echo "❌ Failed")"
echo "   - Admin Interface: $([ "$ADMIN_CODE" = "200" ] || [ "$ADMIN_CODE" = "302" ] && echo "✅ Working" || echo "❌ Failed")"
