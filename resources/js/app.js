import './bootstrap';
import { createInertiaApp } from '@inertiajs/svelte';
import { mount } from 'svelte';

function runApp() {
    createInertiaApp({
        resolve: (name) => {
            const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true });
            const key = `./Pages/${String(name).replace(/\\/g, '/')}.svelte`;
            const module = pages[key];
            if (!module) {
                throw new Error(`Inertia page not found: ${name} (key: ${key}). Available: ${Object.keys(pages).join(', ')}`);
            }
            // Inertia Svelte App expects resolved value to have .default (see App.svelte: h(component.default, ...))
            const component = module.default ?? module;
            return component ? { default: component, layout: module.layout } : module;
        },
        setup({ el, App, props }) {
            mount(App, { target: el, props });
        },
    });
}

function showBootError(msg) {
    const el = document.getElementById('app');
    if (el) {
        el.innerHTML = `<div style="padding:1rem;font-family:monospace;white-space:pre-wrap;color:#c00;">${msg}</div>`;
    }
}

try {
    runApp();
} catch (err) {
    console.error('[Inertia] Boot failed:', err);
    showBootError(`Inertia boot error:\n${err?.message ?? String(err)}`);
    throw err;
}

window.addEventListener('error', (event) => {
    console.error('[Inertia] Runtime error:', event.error ?? event.message);
    if (document.getElementById('app')?.innerHTML === '' || document.getElementById('app')?.children.length === 0) {
        showBootError(`Runtime error: ${(event.error?.message ?? event.message) || 'Unknown'}`);
    }
});
