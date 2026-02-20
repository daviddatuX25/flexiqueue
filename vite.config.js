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
        port: parseInt(process.env.VITE_PORT || '5173', 10),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
