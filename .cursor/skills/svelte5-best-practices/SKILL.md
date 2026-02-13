---
name: svelte5-best-practices
description: "Svelte 5 runes, snippets, and modern best practices for TypeScript and component development. Use when writing, reviewing, or refactoring Svelte 5 components. Triggers on: Svelte components, runes ($state, $derived, $effect, $props, $bindable, $inspect), snippets ({#snippet}, {@render}), event handling, Svelte 4 to Svelte 5 migration, store to rune migration, slots to snippets migration, TypeScript props typing, generic components, performance optimization, or component testing."
license: MIT
metadata:
 author: ejirocodes
 version: '1.1.0'
---

# Svelte 5 Best Practices

**Note:** This project uses Inertia.js for routing and data loading, NOT SvelteKit. For page routing, forms, and data flow, see the `laravel-inertia-svelte` skill instead.

## Quick Reference

| Topic | When to Use | Reference |
|-------|-------------|-----------|
| **Runes** | $state, $derived, $effect, $props, $bindable, $inspect | [runes.md](references/runes.md) |
| **Snippets** | Replacing slots, {#snippet}, {@render} | [snippets.md](references/snippets.md) |
| **Events** | onclick handlers, callback props, context API | [events.md](references/events.md) |
| **TypeScript** | Props typing, generic components | [typescript.md](references/typescript.md) |
| **Migration** | Svelte 4 to 5, stores to runes | [migration.md](references/migration.md) |
| **Performance** | Universal reactivity, avoiding over-reactivity | [performance.md](references/performance.md) |

## Essential Patterns

### Reactive State

```svelte
<script>
  let count = $state(0); // Reactive state
  let doubled = $derived(count * 2); // Computed value
</script>
```

### Component Props

```svelte
<script>
  let { name, count = 0 } = $props();
  let { value = $bindable() } = $props(); // Two-way binding
</script>
```

### Snippets (replacing slots)

```svelte
<script>
  let { children, header } = $props();
</script>

<div>
  {@render header?.()}
  {@render children()}
</div>
```

### Event Handlers

```svelte
<button onclick={() => count++}>Click</button>
```

### Callback Props (replacing createEventDispatcher)

```svelte
<script>
  let { onclick } = $props();
</script>

<button onclick={() => onclick?.({ data })}>Click</button>
```

## Common Mistakes

1. **Using `let` without `$state`** - Variables are not reactive without `$state()`
2. **Using `$effect` for derived values** - Use `$derived` instead
3. **Using `on:click` syntax** - Use `onclick` in Svelte 5
4. **Using `createEventDispatcher`** - Use callback props instead
5. **Using `<slot>`** - Use snippets with `{@render}`
6. **Forgetting `$bindable()`** - Required for `bind:` to work
7. **Using SvelteKit patterns** - No `+page.svelte`, load functions, or `$app/*` imports (use Inertia instead)
