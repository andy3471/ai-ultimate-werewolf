import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

function envString(value: unknown): string | undefined {
    if (typeof value === 'string' && value.length > 0) {
        return value;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return undefined;
}

const soketi = window.__SOKETI__;
const key = envString(soketi?.key) ?? envString(import.meta.env.VITE_PUSHER_APP_KEY);
const host = envString(soketi?.host) ?? envString(import.meta.env.VITE_PUSHER_HOST);
const port = Number(envString(soketi?.port) ?? envString(import.meta.env.VITE_PUSHER_PORT) ?? 443);
const scheme = envString(soketi?.scheme) ?? envString(import.meta.env.VITE_PUSHER_SCHEME) ?? 'https';
const cluster = envString(soketi?.cluster) ?? envString(import.meta.env.VITE_PUSHER_APP_CLUSTER) ?? 'mt1';

window.Pusher = Pusher;

if (key) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });
}
