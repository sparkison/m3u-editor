#!/usr/bin/env node

/**
 * MediaFlow Microservice Server
 * 
 * Run this script to start the JavaScript microservice that complements
 * the Laravel MediaFlow proxy implementation.
 */

import express from 'express';
import cors from 'cors';
import { createServer } from 'http';
import { WebSocketServer } from 'ws';
import MediaFlowMicroservice from './mediaflow-microservice.js';

const app = express();
const port = process.env.MEDIAFLOW_MICROSERVICE_PORT || 3001;
const laravelApiUrl = process.env.LARAVEL_API_URL || 'http://localhost';
const sslVerify = process.env.SSL_VERIFY !== 'false'; // Default to true, but allow override via environment

// Middleware
app.use(cors());
app.use(express.json());

// Initialize MediaFlow Microservice
const microservice = new MediaFlowMicroservice({
    port,
    laravelApiUrl,
    enableWebSocket: true,
    enableStreamMonitoring: true,
    enableHlsProcessing: true,
    sslVerify
});

// Log SSL verification status (only if different from default)
if (!sslVerify) {
    console.log(`⚠️  SSL verification disabled for development use`);
}

// REST API Endpoints
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'MediaFlow Microservice',
        version: '1.0.0',
        timestamp: new Date().toISOString(),
        activeStreams: microservice.activeStreams.size,
        wsConnections: microservice.wsConnections.size
    });
});

app.get('/streams', (req, res) => {
    const streams = Array.from(microservice.activeStreams.entries()).map(([id, data]) => ({
        id,
        ...data
    }));
    
    res.json({ streams });
});

app.get('/streams/:id/stats', (req, res) => {
    const streamId = req.params.id;
    const stats = microservice.streamStats.get(streamId);
    
    if (!stats) {
        return res.status(404).json({ error: 'Stream not found' });
    }
    
    res.json({ stats });
});

app.post('/streams/:id/register', (req, res) => {
    const streamId = req.params.id;
    const streamData = req.body;
    
    microservice.registerStream(streamId, streamData);
    
    res.json({ 
        success: true, 
        message: 'Stream registered',
        streamId 
    });
});

app.delete('/streams/:id', (req, res) => {
    const streamId = req.params.id;
    
    microservice.unregisterStream(streamId);
    
    res.json({ 
        success: true, 
        message: 'Stream unregistered',
        streamId 
    });
});

// Laravel event handling endpoint
app.post('/events', (req, res) => {
    try {
        const { event, data } = req.body;
        
        console.log(`📨 Received Laravel event: ${event}`, data);
        
        // Handle different event types
        switch (event) {
            case 'stream_started':
                microservice.handleStreamStarted(data);
                break;
            case 'stream_stopped':
                microservice.handleStreamStopped(data);
                break;
            default:
                console.log(`Unknown event type: ${event}`);
        }
        
        res.json({ success: true, message: 'Event processed' });
    } catch (error) {
        console.error('Event processing error:', error);
        res.status(500).json({ error: 'Failed to process event' });
    }
});

app.post('/hls/process', async (req, res) => {
    try {
        const { manifest, baseUrl } = req.body;
        
        if (!manifest) {
            return res.status(400).json({ error: 'Manifest content required' });
        }
        
        const processedManifest = await microservice.processHlsManifest(manifest, baseUrl);
        
        res.json({ 
            success: true, 
            processedManifest 
        });
    } catch (error) {
        res.status(500).json({ 
            error: 'Failed to process HLS manifest',
            message: error.message 
        });
    }
});

// Error handling
app.use((err, req, res, next) => {
    console.error('Express error:', err);
    res.status(500).json({ 
        error: 'Internal server error',
        message: err.message 
    });
});

// Create HTTP server
const server = createServer(app);

// Start server
server.listen(port, () => {
    console.log(`🚀 MediaFlow Microservice HTTP server running on port ${port}`);
    console.log(`📡 WebSocket server running on port ${port + 1}`);
    console.log(`🔗 Laravel API URL: ${laravelApiUrl}`);
});

// Handle graceful shutdown
process.on('SIGTERM', () => {
    console.log('🛑 SIGTERM received, shutting down gracefully');
    server.close(() => {
        console.log('✅ Server closed');
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    console.log('🛑 SIGINT received, shutting down gracefully');
    server.close(() => {
        console.log('✅ Server closed');
        process.exit(0);
    });
});

export default server;
