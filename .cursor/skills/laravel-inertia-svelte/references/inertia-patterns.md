# Inertia.js v2 + Svelte 5 Patterns

## Table of Contents
- [Page Component Structure](#page-component-structure)
- [Typing Page Props](#typing-page-props)
- [useForm](#useform)
- [usePage and Shared Data](#usepage-and-shared-data)
- [Router](#router)
- [Link Component](#link-component)
- [Partial Reloads](#partial-reloads)
- [Validation Errors](#validation-errors)

---

## Page Component Structure

Inertia pages are regular Svelte 5 components. Props come directly from the Laravel controller.

```svelte
<!-- resources/js/Pages/Station/Index.svelte -->
<script lang="ts">
  import AppLayout from '@/Layouts/AppLayout.svelte';
  import type { Station, Session } from '@/types';
  
  // Props passed from Inertia::render() in Laravel controller
  let { 
    stations, 
    currentSession,
    canTransfer 
  }: { 
    stations: Station[]; 
    currentSession: Session | null;
    canTransfer: boolean;
  } = $props();
</script>

<AppLayout title="Station">
  <h1>Stations</h1>
  {#each stations as station}
    <div class="card">{station.name}</div>
  {/each}
</AppLayout>
```

**Key differences from SvelteKit:**
- NO `+page.svelte` naming — use `Index.svelte`, `Show.svelte`, `Edit.svelte`
- NO `data` wrapper — props are passed directly
- NO load functions — data comes from Laravel controller
- NO `$app/*` imports — use `@inertiajs/svelte` instead

---

## Typing Page Props

Define interfaces in a shared types file:

```typescript
// resources/js/types/index.ts
export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'supervisor' | 'staff';
}

export interface Station {
  id: number;
  name: string;
  type: 'triage' | 'processing' | 'release';
  current_session_id: number | null;
}

export interface PageProps {
  auth: {
    user: User | null;
  };
  flash: {
    success?: string;
    error?: string;
  };
  errors: Record<string, string>;
}
```

Use in components:

```svelte
<script lang="ts">
  import type { Station, PageProps } from '@/types';
  
  let { stations }: { stations: Station[] } = $props();
</script>
```

---

## useForm

For form submissions with automatic error handling and processing state.

### Basic Usage

```svelte
<script lang="ts">
  import { useForm } from '@inertiajs/svelte';
  
  const form = useForm({
    email: '',
    password: '',
    remember: false,
  });
  
  function submit() {
    $form.post('/login', {
      onSuccess: () => {
        // Handle success
      },
      onError: (errors) => {
        // Handle errors
      },
    });
  }
</script>

<form onsubmit={e => { e.preventDefault(); submit(); }}>
  <input type="email" bind:value={$form.email} />
  {#if $form.errors.email}
    <span class="text-error text-sm">{$form.errors.email}</span>
  {/if}
  
  <input type="password" bind:value={$form.password} />
  {#if $form.errors.password}
    <span class="text-error text-sm">{$form.errors.password}</span>
  {/if}
  
  <label>
    <input type="checkbox" bind:checked={$form.remember} />
    Remember me
  </label>
  
  <button type="submit" class="btn btn-primary" disabled={$form.processing}>
    {$form.processing ? 'Logging in...' : 'Login'}
  </button>
</form>
```

### Form Methods

```svelte
<script lang="ts">
  const form = useForm({ name: '', status: '' });
  
  // POST request
  $form.post('/sessions');
  
  // PUT request
  $form.put(`/sessions/${id}`);
  
  // PATCH request
  $form.patch(`/sessions/${id}`);
  
  // DELETE request
  $form.delete(`/sessions/${id}`);
  
  // Reset form to initial values
  $form.reset();
  
  // Reset specific fields
  $form.reset('name', 'status');
  
  // Clear errors
  $form.clearErrors();
  
  // Clear specific error
  $form.clearErrors('name');
  
  // Transform data before submission
  $form.transform((data) => ({
    ...data,
    name: data.name.trim(),
  }));
</script>
```

### Form Properties

| Property | Type | Description |
|----------|------|-------------|
| `$form.data` | object | Current form data |
| `$form.errors` | object | Validation errors keyed by field |
| `$form.hasErrors` | boolean | True if any errors exist |
| `$form.processing` | boolean | True while request is in flight |
| `$form.progress` | object | Upload progress (for file uploads) |
| `$form.wasSuccessful` | boolean | True if last request succeeded |
| `$form.recentlySuccessful` | boolean | True for 2 seconds after success |
| `$form.isDirty` | boolean | True if form has been modified |

---

## usePage and Shared Data

Access shared data passed from `HandleInertiaRequests` middleware.

### Middleware Setup (Laravel)

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'auth' => [
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'role' => $request->user()->role,
            ] : null,
        ],
        'flash' => [
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
        ],
        'activeProgram' => Program::active()->first(),
    ]);
}
```

### Accessing in Svelte

```svelte
<script lang="ts">
  import { usePage } from '@inertiajs/svelte';
  import type { PageProps } from '@/types';
  
  const page = usePage<PageProps>();
  
  // Access with $derived for reactivity
  let user = $derived($page.props.auth?.user);
  let flash = $derived($page.props.flash);
  let activeProgram = $derived($page.props.activeProgram);
</script>

{#if user}
  <span>Logged in as {user.name} ({user.role})</span>
{/if}

{#if flash.success}
  <div class="alert alert-success">{flash.success}</div>
{/if}

{#if flash.error}
  <div class="alert alert-error">{flash.error}</div>
{/if}
```

---

## Router

Programmatic navigation without forms.

### Basic Navigation

```svelte
<script lang="ts">
  import { router } from '@inertiajs/svelte';
  
  function goToStation(id: number) {
    router.visit(`/station/${id}`);
  }
  
  function logout() {
    router.post('/logout');
  }
  
  function refreshData() {
    router.reload();
  }
</script>
```

### Router Options

```svelte
<script lang="ts">
  import { router } from '@inertiajs/svelte';
  
  router.visit('/station/5', {
    method: 'get',                    // HTTP method
    data: { filter: 'active' },       // Query params or body
    replace: true,                    // Replace history entry
    preserveState: true,              // Keep component state
    preserveScroll: true,             // Keep scroll position
    only: ['sessions'],               // Partial reload (see below)
    onBefore: (visit) => {            // Before request
      return confirm('Navigate away?');
    },
    onStart: (visit) => {},           // Request started
    onProgress: (progress) => {},     // Upload progress
    onSuccess: (page) => {},          // Success callback
    onError: (errors) => {},          // Validation errors
    onFinish: (visit) => {},          // Request finished
  });
</script>
```

### POST/PUT/DELETE with Router

```svelte
<script lang="ts">
  import { router } from '@inertiajs/svelte';
  
  function callNext() {
    router.post(`/api/stations/${stationId}/call-next`, {}, {
      preserveScroll: true,
      onSuccess: () => {
        // Update local state if needed
      },
    });
  }
  
  function transferSession(sessionId: number, targetStationId: number) {
    router.post(`/api/sessions/${sessionId}/transfer`, {
      target_station_id: targetStationId,
      mode: 'standard',
    });
  }
</script>
```

---

## Link Component

Client-side navigation that preserves SPA behavior.

```svelte
<script lang="ts">
  import { Link } from '@inertiajs/svelte';
</script>

<!-- Basic link -->
<Link href="/stations">Stations</Link>

<!-- With method -->
<Link href="/logout" method="post" as="button" class="btn">
  Logout
</Link>

<!-- Preserve scroll position -->
<Link href="/stations" preserveScroll>Stations</Link>

<!-- Replace history (back button skips this page) -->
<Link href="/dashboard" replace>Dashboard</Link>

<!-- Partial reload -->
<Link href="/dashboard" only={['stats']}>Refresh Stats</Link>

<!-- With data (query params for GET, body for POST) -->
<Link href="/stations" data={{ filter: 'active' }}>Active Only</Link>

<!-- Styled as button -->
<Link href="/admin/programs/create" class="btn btn-primary">
  New Program
</Link>
```

---

## Partial Reloads

Reload only specific props without full page reload.

```svelte
<script lang="ts">
  import { router } from '@inertiajs/svelte';
  
  // Reload only the 'sessions' prop
  function refreshSessions() {
    router.reload({ only: ['sessions'] });
  }
  
  // Reload everything except 'settings'
  function refreshWithoutSettings() {
    router.reload({ except: ['settings'] });
  }
</script>
```

**Laravel controller must return lazy props for this to be effective:**

```php
public function index()
{
    return Inertia::render('Station/Index', [
        // Always included
        'station' => $station,
        
        // Only loaded when explicitly requested
        'sessions' => Inertia::lazy(fn () => 
            Session::where('station_id', $station->id)->get()
        ),
        
        // Deferred - loaded after initial page load
        'stats' => Inertia::defer(fn () => 
            $this->statsService->getStationStats($station->id)
        ),
    ]);
}
```

---

## Validation Errors

Laravel validation errors automatically flow to Inertia.

### Laravel Form Request

```php
// app/Http/Requests/BindSessionRequest.php
class BindSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bind', Session::class);
    }
    
    public function rules(): array
    {
        return [
            'token_hash' => ['required', 'string', 'exists:tokens,hash'],
            'track_id' => ['required', 'integer', 'exists:service_tracks,id'],
        ];
    }
    
    public function messages(): array
    {
        return [
            'token_hash.exists' => 'This token is not registered in the system.',
            'track_id.required' => 'Please select a service track.',
        ];
    }
}
```

### Controller Using Form Request

```php
public function bind(BindSessionRequest $request)
{
    // Validation already passed if we get here
    $validated = $request->validated();
    
    $session = $this->sessionService->bind(
        $validated['token_hash'],
        $validated['track_id']
    );
    
    return redirect()->route('triage.index')
        ->with('success', "Client bound to token {$session->alias}");
}
```

### Displaying Errors in Svelte

```svelte
<script lang="ts">
  import { useForm } from '@inertiajs/svelte';
  
  const form = useForm({
    token_hash: '',
    track_id: null as number | null,
  });
</script>

<form onsubmit={e => { e.preventDefault(); $form.post('/api/sessions/bind'); }}>
  <div class="form-control">
    <label class="label">Token</label>
    <input 
      type="text" 
      bind:value={$form.token_hash}
      class="input input-bordered"
      class:input-error={$form.errors.token_hash}
    />
    {#if $form.errors.token_hash}
      <label class="label">
        <span class="label-text-alt text-error">{$form.errors.token_hash}</span>
      </label>
    {/if}
  </div>
  
  <div class="form-control">
    <label class="label">Track</label>
    <select 
      bind:value={$form.track_id}
      class="select select-bordered"
      class:select-error={$form.errors.track_id}
    >
      <option value={null}>Select track...</option>
      {#each tracks as track}
        <option value={track.id}>{track.name}</option>
      {/each}
    </select>
    {#if $form.errors.track_id}
      <label class="label">
        <span class="label-text-alt text-error">{$form.errors.track_id}</span>
      </label>
    {/if}
  </div>
  
  <!-- Show all errors summary -->
  {#if $form.hasErrors}
    <div class="alert alert-error mt-4">
      <ul>
        {#each Object.values($form.errors) as error}
          <li>{error}</li>
        {/each}
      </ul>
    </div>
  {/if}
  
  <button type="submit" class="btn btn-primary" disabled={$form.processing}>
    Bind Token
  </button>
</form>
```

### Accessing Errors via usePage

For errors outside forms (e.g., from redirects):

```svelte
<script lang="ts">
  import { usePage } from '@inertiajs/svelte';
  
  const page = usePage();
  
  let errors = $derived($page.props.errors);
</script>

{#if Object.keys(errors).length > 0}
  <div class="alert alert-error">
    {#each Object.entries(errors) as [field, message]}
      <p><strong>{field}:</strong> {message}</p>
    {/each}
  </div>
{/if}
```
