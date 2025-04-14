import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const env = import.meta.env.VITE_REVERB_ENV ?? 'production';
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https'
let wsPort = import.meta.env.VITE_REVERB_PORT;
let wssPort = scheme === 'https' ? 443 : wsPort;
if (env !== 'production') {
    wssPort = wsPort
}
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort,
    wssPort,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
