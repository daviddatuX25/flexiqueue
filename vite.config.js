import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        svelte({
            compilerOptions: {
                dev: true,
            },
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: parseInt(process.env.VITE_PORT || '5173', 10),
        strictPort: true,
        // Important for Sail/WSL: browser must NOT try to load from 0.0.0.0
        origin: process.env.VITE_ORIGIN || `http://localhost:${parseInt(process.env.VITE_PORT || '5173', 10)}`,
        // Allow Laravel app (localhost on any port) to load dev assets without CORS issues.
        cors: {
            origin: /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
        },
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            clientPort: parseInt(process.env.VITE_PORT || '5173', 10),
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
