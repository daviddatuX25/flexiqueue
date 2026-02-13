---
name: laravel-inertia-svelte
description: "Laravel 12 + Inertia.js v2 + Svelte 5 full-stack patterns. Use when writing Laravel controllers with Inertia responses, Svelte page components, useForm, usePage, router, Link, shared data via HandleInertiaRequests, Form Request validation, Service classes, Eloquent models, Echo/Reverb broadcasting, or any Laravel-to-Svelte data flow. Triggers on: Inertia::render, $props in pages, form.post, router.visit, usePage, HandleInertiaRequests, broadcasting, Echo, Reverb, Form Requests, Service classes."
---

# Laravel + Inertia.js + Svelte 5 Patterns

**This project uses Inertia.js for the Laravel-Svelte bridge, NOT SvelteKit.**

## Quick Reference

| Topic | When to Use | Reference |
|-------|-------------|-----------|
| **Inertia Patterns** | Page props, useForm, usePage, router, Link, shared data, validation errors | [inertia-patterns.md](references/inertia-patterns.md) |
| **Laravel Patterns** | Controllers, Services, Form Requests, Eloquent, migrations | [laravel-patterns.md](references/laravel-patterns.md) |
| **Real-time** | Echo, Reverb, broadcasting, WebSocket channels | [realtime-patterns.md](references/realtime-patterns.md) |

## Essential Patterns

### Controller returning Inertia page

```php
// app/Http/Controllers/StationController.php
public function index()
{
    return Inertia::render('Station/Index', [
        'stations' => Station::with('currentSession')->get(),
        'program' => Program::active()->first(),
    ]);
}
```

### Svelte page receiving props

```svelte
<script lang="ts">
  import type { Station, Program } from '@/types';
  
  let { stations, program }: { stations: Station[]; program: Program } = $props();
</script>

<h1>{program.name}</h1>
{#each stations as station}
  <div>{station.name}</div>
{/each}
```

### Form submission with useForm

```svelte
<script lang="ts">
  import { useForm } from '@inertiajs/svelte';
  
  const form = useForm({
    token_hash: '',
    track_id: null,
  });
  
  function submit() {
    $form.post('/api/sessions/bind');
  }
</script>

<form onsubmit={e => { e.preventDefault(); submit(); }}>
  <input bind:value={$form.token_hash} />
  {#if $form.errors.token_hash}
    <span class="text-error">{$form.errors.token_hash}</span>
  {/if}
  <button type="submit" disabled={$form.processing}>Bind</button>
</form>
```

### Accessing shared data (auth user)

```svelte
<script lang="ts">
  import { usePage } from '@inertiajs/svelte';
  
  const page = usePage();
  
  // Shared data from HandleInertiaRequests middleware
  let user = $derived($page.props.auth?.user);
</script>

{#if user}
  <span>Welcome, {user.name}</span>
{/if}
```

## Common Mistakes

1. **Using SvelteKit patterns** — No `+page.svelte`, load functions, `$app/*` imports, or form actions
2. **Using `data` prop** — Inertia passes props directly, not wrapped in `data`
3. **Forgetting `$form`** — useForm returns a store, access with `$form.field`
4. **JSON responses for pages** — Use `Inertia::render()`, not `response()->json()`
5. **Validation in controller** — Use Form Request classes, errors auto-flow to `$form.errors`
6. **Business logic in controller** — Extract to Service classes in `app/Services/`
7. **Missing `$page.props`** — usePage returns a store, access with `$page.props`
8. **Not cleaning up Echo listeners** — Use `$effect` return function to unsubscribe
