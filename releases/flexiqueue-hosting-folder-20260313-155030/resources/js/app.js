import './bootstrap';
import { createInertiaApp } from '@inertiajs/svelte';
import { mount } from 'svelte';

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
