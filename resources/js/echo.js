import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const host = window.location.hostname;
const scheme = window.location.protocol.slice(0, -1); // remove the trailing ':'
const port = window.location.port;

const echoConfig = {
    broadcaster: 'reverb',
    key: "5e2a227aacd3bc04713e595428195896617947b8f5ec11db31029abd13b13538",
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
};

window.Echo = new Echo(echoConfig);
