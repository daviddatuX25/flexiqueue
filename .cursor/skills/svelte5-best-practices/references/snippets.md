# Svelte 5 Snippets Reference

## Table of Contents
- [Replacing Slots with Snippets](#replacing-slots-with-snippets)
- [Rendering with @render](#rendering-with-render)

---

## Replacing Slots with Snippets

Svelte 5 replaces `<slot>` with snippets - a more powerful and type-safe composition primitive.

### Default Content (children)

**Svelte 4:**
```svelte
<div>
  <slot></slot>
</div>
```

**Svelte 5:**
```svelte
<script>
  let { children } = $props();
</script>

<div>
  {@render children?.()}
</div>
```

### Named Slots to Named Snippets

**Svelte 4:**
```svelte
<div>
  <slot name="header"></slot>
  <slot></slot>
  <slot name="footer"></slot>
</div>

<!-- Usage -->
<Component>
  <svelte:fragment slot="header">Title</svelte:fragment>
  Content
  <svelte:fragment slot="footer">Footer</svelte:fragment>
</Component>
```

**Svelte 5:**
```svelte
<script>
  let { header, children, footer } = $props();
</script>

<div>
  {@render header?.()}
  {@render children?.()}
  {@render footer?.()}
</div>

<!-- Usage -->
<Component>
  {#snippet header()} Title {/snippet}
  Content
  {#snippet footer()} Footer {/snippet}
</Component>
```

### Slot Props to Snippet Parameters

**Svelte 4:**
```svelte
{#each items as item}
  <slot item={item}></slot>
{/each}

<!-- Usage -->
<Component>
  <div let:item>{item.name}</div>
</Component>
```

**Svelte 5:**
```svelte
<script>
  let { items, children } = $props();
</script>

{#each items as item}
  {@render children?.(item)}
{/each}

<!-- Usage -->
<Component items={items}>
  {#snippet children(item)}
    <div>{item.name}</div>
  {/snippet}
</Component>
```

### Slot Fallback to Snippet Fallback

```svelte
<script>
  let { children } = $props();
</script>

{#if children}
  {@render children()}
{:else}
  Default content
{/if}
```

### Defining Snippets Within Components

```svelte
<script>
  let items = $state([
    { id: 1, name: 'Item 1', type: 'normal' },
    { id: 2, name: 'Item 2', type: 'featured' }
  ]);
</script>

{#snippet normalItem(item)}
  <div>{item.name}</div>
{/snippet}

{#snippet featuredItem(item)}
  <div>*** {item.name} ***</div>
{/snippet}

<div>
  {#each items as item}
    {#if item.type === 'featured'}
      {@render featuredItem(item)}
    {:else}
      {@render normalItem(item)}
    {/if}
  {/each}
</div>
```

### TypeScript Typing

```svelte
<script lang="ts">
  import type { Snippet } from 'svelte';

  interface Item { id: number; name: string; }

  interface Props {
    header?: Snippet;
    children: Snippet<[item: Item]>;
    footer?: Snippet;
  }

  let { header, children, footer }: Props = $props();
</script>
```

---

## Rendering with @render

Snippets must be rendered using `{@render}`. Optional snippets need null-safe calling.

### Basic Snippet Rendering

```svelte
<script>
  let { children } = $props();
</script>

<div>
  {@render children()}
</div>
```

### Optional Snippets

Always use optional chaining for snippets that might not be provided:

```svelte
<script>
  let { children } = $props();
</script>

<div>
  {@render children?.()}
</div>
```

### Conditional Rendering

```svelte
<script>
  let { header, children, footer } = $props();
</script>

<div>
  {#if header}
    {@render header()}
  {/if}

  {@render children?.()}

  {#if footer}
    {@render footer()}
  {/if}
</div>
```

### Rendering with Arguments

```svelte
<script>
  let { items, itemTemplate } = $props();
</script>

<div>
  {#each items as item, index}
    {@render itemTemplate(item, index)}
  {/each}
</div>

<!-- Usage -->
<Component items={items} itemTemplate={itemTemplate}>
  {#snippet itemTemplate(item, index)}
    <div>{index + 1}. {item.name}</div>
  {/snippet}
</Component>
```

### Rendering Multiple Times

Snippets can be rendered multiple times:

```svelte
<script>
  let { icon } = $props();
</script>

<button>
  {@render icon?.()}
  Click me
  {@render icon?.()}
</button>
```

### Rendering Dynamic Snippets

```svelte
<script>
  let { normalView, editView, isEditing } = $props();
  let currentView = $derived(isEditing ? editView : normalView);
</script>

{@render currentView?.()}
```

### TypeScript with @render

```svelte
<script lang="ts">
  import type { Snippet } from 'svelte';

  interface Props {
    children: Snippet;
    header?: Snippet;
    row: Snippet<[data: { id: number; name: string }]>;
  }

  let { children, header, row }: Props = $props();
</script>

{@render header?.()}
{@render children()}
{@render row({ id: 1, name: 'Test' })}
```

### Passing Snippets to Child Components

```svelte
<!-- Parent.svelte -->
<script>
  import Inner from './Inner.svelte';
  let { itemRenderer } = $props();
</script>

<Inner {itemRenderer} />

<!-- Inner.svelte -->
<script>
  let { itemRenderer } = $props();
</script>

{#each items as item}
  {@render itemRenderer?.(item)}
{/each}
```
