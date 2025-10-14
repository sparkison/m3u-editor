import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const disabled = import.meta.env.VITE_WEBSOCKETS_DISABLED ?? 'false';

if (disabled === 'true') {
    window.Echo = null;
} else {
    window.Pusher = Pusher;
    
    const scheme = import.meta.env.VITE_WEBSOCKET_SCHEME ?? 'https';
    const host = import.meta.env.VITE_WEBSOCKET_HOST || '';
    const port = import.meta.env.VITE_WEBSOCKET_PORT;
    
    // For reverse proxy setup, use the port from environment
    let wsPort, wssPort;
    
    if (port && port !== '' && port !== 'null') {
        wsPort = parseInt(port);
        wssPort = parseInt(port);
    } else {
        // Fallback to default ports
        wsPort = scheme === 'https' ? 443 : 80;
        wssPort = scheme === 'https' ? 443 : 80;
    }

    const echoConfig = {
        broadcaster: 'reverb',
        key: import.meta.env.VITE_WEBSOCKET_APP_KEY,
        wsHost: host,
        wsPort: wsPort,
        wssPort: wssPort,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    };

    window.Echo = new Echo(echoConfig);
}

