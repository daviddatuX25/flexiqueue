import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
    plugins: [
        svelte({ hot: !process.env.VITEST }),
    ],
    test: {
        include: ['resources/js/**/*.{test,spec}.{js,ts}'],
        globals: true,
        environment: 'jsdom',
    },
});
