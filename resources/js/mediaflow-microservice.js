/**
 * MediaFlow Proxy Microservice
 * 
 * A JavaScript-based microservice for handling advanced streaming operations
 * that complement the Laravel MediaFlow proxy implementation.
 * 
 * Features:
 * - Real-time stream monitoring
 * - Advanced HLS processing
 * - WebSocket-based communication
 * - Stream quality monitoring
 */

import { EventEmitter } from 'events';
import https from 'https';
import http from 'http';

class MediaFlowMicroservice extends EventEmitter {
    constructor(options = {}) {
        super();
        
        this.config = {
            port: options.port || 3001,
            laravelApiUrl: options.laravelApiUrl || 'http://localhost',
            enableWebSocket: options.enableWebSocket || true,
            enableStreamMonitoring: options.enableStreamMonitoring || true,
            enableHlsProcessing: options.enableHlsProcessing || true,
            sslVerify: options.sslVerify !== false, // Default to true, but allow override
            ...options
        };
        
        // Create HTTPS agent for self-signed certificates
        this.httpsAgent = new https.Agent({
            rejectUnauthorized: this.config.sslVerify
        });
        
        this.activeStreams = new Map();
        this.streamStats = new Map();
        this.wsConnections = new Set();
        
        this.init();
    }
    
    async init() {
        console.log('🚀 Starting MediaFlow Microservice...');
        console.log(`🔒 SSL verification: ${this.config.sslVerify ? 'enabled' : 'disabled'}`);
        
        if (!this.config.sslVerify) {
            console.log('⚠️  SSL verification is disabled - suitable for development with self-signed certificates');
        }
        
        if (this.config.enableWebSocket) {
            await this.initWebSocket();
        }
        
        if (this.config.enableStreamMonitoring) {
            this.initStreamMonitoring();
        }
        
        if (this.config.enableHlsProcessing) {
            this.initHlsProcessing();
        }
        
        console.log(`✅ MediaFlow Microservice running on port ${this.config.port}`);
    }
    
    async initWebSocket() {
        // WebSocket server for real-time communication
        const { WebSocketServer } = await import('ws');
        
        this.wss = new WebSocketServer({ port: this.config.port + 1 });
        
        this.wss.on('connection', (ws) => {
            console.log('📡 New WebSocket connection');
            this.wsConnections.add(ws);
            
            ws.on('close', () => {
                this.wsConnections.delete(ws);
            });
            
            ws.on('message', (data) => {
                try {
                    const message = JSON.parse(data);
                    this.handleWebSocketMessage(ws, message);
                } catch (error) {
                    console.error('WebSocket message error:', error);
                }
            });
        });
    }
    
    initStreamMonitoring() {
        // Monitor active streams and send updates
        setInterval(() => {
            this.monitorStreams();
        }, 5000); // Check every 5 seconds
    }
    
    initHlsProcessing() {
        // Initialize HLS processing capabilities
        console.log('🎬 HLS processing initialized');
    }
    
    handleWebSocketMessage(ws, message) {
        switch (message.type) {
            case 'subscribe_stream':
                this.handleStreamSubscription(ws, message.streamId);
                break;
            case 'get_stream_stats':
                this.sendStreamStats(ws, message.streamId);
                break;
            case 'ping':
                ws.send(JSON.stringify({ type: 'pong', timestamp: Date.now() }));
                break;
            default:
                console.log('Unknown WebSocket message type:', message.type);
        }
    }
    
    handleStreamSubscription(ws, streamId) {
        ws.subscribedStreams = ws.subscribedStreams || new Set();
        ws.subscribedStreams.add(streamId);
        
        // Send current stats if available
        if (this.streamStats.has(streamId)) {
            ws.send(JSON.stringify({
                type: 'stream_stats',
                streamId,
                stats: this.streamStats.get(streamId)
            }));
        }
    }
    
    sendStreamStats(ws, streamId) {
        const stats = this.streamStats.get(streamId) || this.getDefaultStats(streamId);
        ws.send(JSON.stringify({
            type: 'stream_stats',
            streamId,
            stats
        }));
    }
    
    async monitorStreams() {
        try {
            // Use native HTTP/HTTPS modules instead of fetch for better SSL control
            const url = new URL(`${this.config.laravelApiUrl}/api/mediaflow/proxy/health`);
            const isHttps = url.protocol === 'https:';
            const httpModule = isHttps ? https : http;
            
            const options = {
                hostname: url.hostname,
                port: url.port || (isHttps ? 443 : 80),
                path: url.pathname + url.search,
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'User-Agent': 'MediaFlow-Microservice/1.0'
                }
            };
            
            // Add SSL configuration for HTTPS
            if (isHttps) {
                options.rejectUnauthorized = this.config.sslVerify;
            }
            
            const response = await new Promise((resolve, reject) => {
                const req = httpModule.request(options, (res) => {
                    let data = '';
                    res.on('data', (chunk) => data += chunk);
                    res.on('end', () => {
                        resolve({
                            ok: res.statusCode >= 200 && res.statusCode < 300,
                            status: res.statusCode,
                            data
                        });
                    });
                });
                
                req.on('error', reject);
                req.setTimeout(5000, () => {
                    req.destroy();
                    reject(new Error('Request timeout'));
                });
                req.end();
            });
            
            if (response.ok) {
                // Update stream monitoring logic here
                this.updateStreamStats();
            }
        } catch (error) {
            console.error('Stream monitoring error:', error.message);
        }
    }
    
    updateStreamStats() {
        // Broadcast updates to subscribed WebSocket clients
        for (const ws of this.wsConnections) {
            if (ws.subscribedStreams) {
                for (const streamId of ws.subscribedStreams) {
                    if (this.streamStats.has(streamId)) {
                        ws.send(JSON.stringify({
                            type: 'stream_update',
                            streamId,
                            stats: this.streamStats.get(streamId)
                        }));
                    }
                }
            }
        }
    }
    
    getDefaultStats(streamId) {
        return {
            streamId,
            status: 'unknown',
            bitrate: 0,
            resolution: null,
            viewers: 0,
            uptime: 0,
            lastUpdate: Date.now()
        };
    }
    
    // HLS Processing Methods
    async processHlsManifest(manifestContent, baseUrl) {
        console.log('🎬 Processing HLS manifest');
        
        // Advanced HLS processing logic
        const lines = manifestContent.split('\n');
        const processedLines = [];
        
        for (const line of lines) {
            if (line.trim() && !line.startsWith('#')) {
                // Process URLs in the manifest
                const processedUrl = await this.processHlsUrl(line.trim(), baseUrl);
                processedLines.push(processedUrl);
            } else {
                processedLines.push(line);
            }
        }
        
        return processedLines.join('\n');
    }
    
    async processHlsUrl(url, baseUrl) {
        // Advanced URL processing logic
        if (!url.startsWith('http')) {
            url = new URL(url, baseUrl).toString();
        }
        
        // Add monitoring, analytics, or other processing here
        return url;
    }
    
    // Stream Management Methods
    registerStream(streamId, streamData) {
        this.activeStreams.set(streamId, {
            ...streamData,
            startTime: Date.now(),
            status: 'active'
        });
        
        this.streamStats.set(streamId, {
            streamId,
            status: 'active',
            startTime: Date.now(),
            bitrate: 0,
            viewers: 1,
            ...streamData
        });
        
        this.emit('streamStarted', streamId, streamData);
    }
    
    unregisterStream(streamId) {
        if (this.activeStreams.has(streamId)) {
            const streamData = this.activeStreams.get(streamId);
            this.activeStreams.delete(streamId);
            this.streamStats.delete(streamId);
            
            this.emit('streamStopped', streamId, streamData);
        }
    }
    
    updateStreamStats(streamId, stats) {
        if (this.streamStats.has(streamId)) {
            const currentStats = this.streamStats.get(streamId);
            this.streamStats.set(streamId, {
                ...currentStats,
                ...stats,
                lastUpdate: Date.now()
            });
        }
    }
    
    // Laravel Event Handlers
    handleStreamStarted(data) {
        console.log('🎬 Stream started event:', data);
        
        // Create a stream entry for tracking
        const streamId = `playlist_${data.playlist_id}_${Date.now()}`;
        this.registerStream(streamId, {
            type: 'laravel_stream',
            playlist_id: data.playlist_id,
            timestamp: data.timestamp,
            status: 'started'
        });
        
        // Broadcast to WebSocket clients
        this.broadcastToWebSocket({
            type: 'laravel_stream_started',
            data: data
        });
    }
    
    handleStreamStopped(data) {
        console.log('🛑 Stream stopped event:', data);
        
        // Find and remove corresponding stream
        for (const [streamId, streamData] of this.activeStreams.entries()) {
            if (streamData.type === 'laravel_stream' && streamData.playlist_id === data.playlist_id) {
                this.unregisterStream(streamId);
                break;
            }
        }
        
        // Broadcast to WebSocket clients
        this.broadcastToWebSocket({
            type: 'laravel_stream_stopped',
            data: data
        });
    }
    
    broadcastToWebSocket(message) {
        const messageStr = JSON.stringify(message);
        for (const ws of this.wsConnections) {
            if (ws.readyState === 1) { // WebSocket.OPEN
                ws.send(messageStr);
            }
        }
    }
}

// Export for Node.js usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MediaFlowMicroservice;
}

// Export for ES6 modules
export default MediaFlowMicroservice;
