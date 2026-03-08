import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
import { toaster } from './lib/toaster.js';

window.Pusher = Pusher;

// Reverb WebSocket:
// - Dev (`npm run dev`, Sail or local): connect directly to Reverb on :6001.
// - Production build (Orange Pi behind nginx): connect to same-origin `/app` on :80/:443 and let nginx proxy to Reverb.
//
// Why: browsers often can't reach :6001 directly in production (firewall / routing). Nginx already proxies `/app` to 127.0.0.1:6001.
//
// Override (build-time) if needed:
// - VITE_REVERB_VIA_PROXY=true  -> same-origin (proxy mode)
// - VITE_REVERB_VIA_PROXY=false -> direct host:port (direct mode)
const isBrowser = typeof window !== 'undefined';
const envViaProxy = import.meta.env.VITE_REVERB_VIA_PROXY;
const viaProxy =
    envViaProxy != null
        ? String(envViaProxy).toLowerCase() === 'true'
        : import.meta.env.PROD;

const envHost = import.meta.env.VITE_REVERB_HOST ?? 'localhost';
const envPort = import.meta.env.VITE_REVERB_PORT ?? '6001';
const envScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

const pageHost = isBrowser ? window.location.hostname : 'localhost';
const pageIsHttps = isBrowser ? window.location.protocol === 'https:' : false;

const forceTLS = viaProxy ? pageIsHttps : envScheme === 'https';
const wsPort = viaProxy
    ? (isBrowser && window.location.port ? window.location.port : (forceTLS ? '443' : '80'))
    : envPort;

// Pusher requires a non-empty key. If missing (e.g. Pi .env without REVERB_APP_KEY at build time), skip Echo
// so pages that guard with "if (!window.Echo) return" still work; set REVERB_APP_KEY + VITE_REVERB_APP_KEY for real-time.
const appKey = import.meta.env.VITE_REVERB_APP_KEY;
if (appKey && String(appKey).trim() !== '') {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: appKey,
        wsHost: viaProxy ? pageHost : envHost,
        wsPort,
        wssPort: wsPort,
        forceTLS,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });

    // Surface runtime WebSocket connection failures via toast. Covers BroadcastTest, Display Board,
    // StationBoard, Station Index, Triage, Dashboard (if using Echo), etc.
    let lastConnectionToastAt = 0;
    const CONNECTION_TOAST_THROTTLE_MS = 30000;

    function showConnectionErrorToast() {
        const now = Date.now();
        if (now - lastConnectionToastAt < CONNECTION_TOAST_THROTTLE_MS) return;
        lastConnectionToastAt = now;
        toaster.error({
            title: 'Live updates unavailable',
            description: 'Some changes may be delayed. You can continue using the app.',
        });
    }

    try {
        const conn = window.Echo.connector?.pusher?.connection;
        if (conn) {
            conn.bind('state_change', (states) => {
                if (states?.current === 'failed' || states?.current === 'unavailable') {
                    showConnectionErrorToast();
                }
            });
            conn.bind('error', () => showConnectionErrorToast());
        }
    } catch (_) {
        // Connector API may change; fail silently
    }
} else {
    window.Echo = null;
}
