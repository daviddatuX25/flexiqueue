# Real-time Patterns: Laravel Reverb + Echo + Svelte 5

## Table of Contents
- [Reverb Setup](#reverb-setup)
- [Echo Initialization](#echo-initialization)
- [Channel Types](#channel-types)
- [Broadcasting Events from Laravel](#broadcasting-events-from-laravel)
- [Listening in Svelte Components](#listening-in-svelte-components)
- [Channel Authorization](#channel-authorization)

---

## Reverb Setup

Laravel Reverb is the WebSocket server for this project.

### Installation

```bash
php artisan install:broadcasting
```

### Environment Configuration

```env
# .env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=flexiqueue
REVERB_APP_KEY=flexiqueue-local-key
REVERB_APP_SECRET=flexiqueue-local-secret
REVERB_HOST="127.0.0.1"
REVERB_PORT=6001
REVERB_SCHEME=http

# For Vite to pass to frontend
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Starting Reverb

```bash
php artisan reverb:start
```

For development with auto-restart:

```bash
php artisan reverb:start --debug
```

---

## Echo Initialization

### Install Dependencies

```bash
npm install laravel-echo pusher-js
```

### Configure Echo

```javascript
// resources/js/echo.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 6001,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 6001,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

export default echo;
```

### Import in App Entry

```javascript
// resources/js/app.js
import { createInertiaApp } from '@inertiajs/svelte';
import { mount } from 'svelte';
import echo from './echo';

// Make echo available globally for components
window.Echo = echo;

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true });
        return pages[`./Pages/${name}.svelte`];
    },
    setup({ el, App, props }) {
        mount(App, { target: el, props });
    },
});
```

---

## Channel Types

### Public Channels

Anyone can listen. No authorization required.

```javascript
// Client
Echo.channel('global.queue')
    .listen('NowServingUpdated', (e) => {
        console.log(e.session);
    });
```

```php
// Server event
public function broadcastOn(): array
{
    return [new Channel('global.queue')];
}
```

### Private Channels

Requires user authentication.

```javascript
// Client
Echo.private(`station.${stationId}`)
    .listen('ClientArrived', (e) => {
        console.log(e.session);
    });
```

```php
// Server event
public function broadcastOn(): array
{
    return [new PrivateChannel("station.{$this->stationId}")];
}
```

### Presence Channels

Like private, but track who's listening (not needed for Phase 1).

---

## Broadcasting Events from Laravel

### Creating a Broadcast Event

```bash
php artisan make:event ClientArrived
```

### Event Structure

```php
// app/Events/ClientArrived.php
namespace App\Events;

use App\Models\Session;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientArrived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(
        public Session $session
    ) {}
    
    public function broadcastOn(): array
    {
        return [
            // Notify the station that has a new client
            new PrivateChannel("station.{$this->session->current_station_id}"),
            // Notify global queue display
            new Channel('global.queue'),
        ];
    }
    
    public function broadcastWith(): array
    {
        return [
            'session' => [
                'id' => $this->session->id,
                'alias' => $this->session->alias,
                'status' => $this->session->status,
                'station_id' => $this->session->current_station_id,
                'track' => $this->session->track->name,
            ],
        ];
    }
    
    public function broadcastAs(): string
    {
        // Optional: customize event name (defaults to class name)
        return 'client.arrived';
    }
}
```

### Dispatching Events

```php
// In service class
use App\Events\ClientArrived;
use App\Events\SessionTransferred;
use App\Events\NowServingUpdated;

public function transfer(Session $session, int $targetStationId): Session
{
    $oldStationId = $session->current_station_id;
    
    // ... update session ...
    
    // Dispatch events
    ClientArrived::dispatch($session);
    SessionTransferred::dispatch($session, $oldStationId);
    NowServingUpdated::dispatch();
    
    return $session;
}
```

### Common Events for FlexiQueue

| Event | Channels | When |
|-------|----------|------|
| `ClientArrived` | `station.{id}`, `global.queue` | After bind or transfer |
| `SessionTransferred` | `station.{from}`, `station.{to}` | Client moves between stations |
| `NowServingUpdated` | `global.queue` | Display board needs refresh |
| `SessionCompleted` | `station.{id}`, `global.queue` | Client finished |
| `QueueLengthChanged` | `global.queue` | Waiting count changes |

---

## Listening in Svelte Components

### Basic Pattern with $effect

```svelte
<script lang="ts">
  import type { Session } from '@/types';
  
  let { stationId }: { stationId: number } = $props();
  
  let waitingQueue = $state<Session[]>([]);
  let currentSession = $state<Session | null>(null);
  
  // Subscribe to real-time updates
  $effect(() => {
    const channel = window.Echo.private(`station.${stationId}`);
    
    channel
      .listen('client.arrived', (e: { session: Session }) => {
        waitingQueue = [...waitingQueue, e.session];
      })
      .listen('SessionTransferred', (e: { session: Session }) => {
        // Remove from our queue if transferred away
        waitingQueue = waitingQueue.filter(s => s.id !== e.session.id);
      })
      .listen('SessionCompleted', (e: { session: Session }) => {
        if (currentSession?.id === e.session.id) {
          currentSession = null;
        }
      });
    
    // Cleanup on unmount or stationId change
    return () => {
      channel.stopListening('client.arrived');
      channel.stopListening('SessionTransferred');
      channel.stopListening('SessionCompleted');
      window.Echo.leave(`station.${stationId}`);
    };
  });
</script>

<div>
  <h2>Waiting: {waitingQueue.length}</h2>
  {#if currentSession}
    <p>Now serving: {currentSession.alias}</p>
  {/if}
</div>
```

### Public Channel for Display Board

```svelte
<!-- resources/js/Pages/Display/Index.svelte -->
<script lang="ts">
  import type { Session } from '@/types';
  
  let { nowServing, queueLength }: { nowServing: Session[]; queueLength: number } = $props();
  
  let currentServing = $state(nowServing);
  let waiting = $state(queueLength);
  
  $effect(() => {
    const channel = window.Echo.channel('global.queue');
    
    channel
      .listen('NowServingUpdated', (e: { serving: Session[] }) => {
        currentServing = e.serving;
      })
      .listen('QueueLengthChanged', (e: { count: number }) => {
        waiting = e.count;
      });
    
    return () => {
      window.Echo.leave('global.queue');
    };
  });
</script>

<div class="text-6xl font-bold">
  {#each currentServing as session}
    <div class="flex justify-between">
      <span>{session.alias}</span>
      <span>{session.currentStation?.name}</span>
    </div>
  {/each}
</div>

<div class="text-2xl mt-8">
  Waiting: {waiting}
</div>
```

### Reusable Hook Pattern

```typescript
// resources/js/composables/useStationChannel.ts
import { onMount, onDestroy } from 'svelte';

export function useStationChannel(
  stationId: number,
  handlers: {
    onClientArrived?: (session: any) => void;
    onSessionTransferred?: (session: any) => void;
    onSessionCompleted?: (session: any) => void;
  }
) {
  let channel: any;
  
  $effect(() => {
    channel = window.Echo.private(`station.${stationId}`);
    
    if (handlers.onClientArrived) {
      channel.listen('client.arrived', handlers.onClientArrived);
    }
    if (handlers.onSessionTransferred) {
      channel.listen('SessionTransferred', handlers.onSessionTransferred);
    }
    if (handlers.onSessionCompleted) {
      channel.listen('SessionCompleted', handlers.onSessionCompleted);
    }
    
    return () => {
      window.Echo.leave(`station.${stationId}`);
    };
  });
}
```

Usage:

```svelte
<script lang="ts">
  import { useStationChannel } from '@/composables/useStationChannel';
  
  let { stationId }: { stationId: number } = $props();
  let queue = $state([]);
  
  useStationChannel(stationId, {
    onClientArrived: (e) => {
      queue = [...queue, e.session];
    },
  });
</script>
```

---

## Channel Authorization

### Define Authorization Rules

```php
// routes/channels.php
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private station channel - only staff assigned to station
Broadcast::channel('station.{stationId}', function (User $user, int $stationId) {
    // Admin and supervisor can access any station
    if (in_array($user->role, ['admin', 'supervisor'])) {
        return true;
    }
    
    // Staff can only access their assigned station
    return $user->role === 'staff' && $user->assigned_station_id === $stationId;
});

// Admin-only channel
Broadcast::channel('admin.dashboard', function (User $user) {
    return $user->role === 'admin';
});
```

### Ensure Auth Routes Are Registered

```php
// routes/web.php or routes/channels.php
Broadcast::routes(['middleware' => ['auth']]);
```

### Handle Authorization Errors in Svelte

```svelte
<script lang="ts">
  let connectionError = $state<string | null>(null);
  
  $effect(() => {
    const channel = window.Echo.private(`station.${stationId}`);
    
    channel
      .subscribed(() => {
        connectionError = null;
      })
      .error((error: any) => {
        if (error?.status === 403) {
          connectionError = 'You are not authorized to view this station.';
        } else {
          connectionError = 'Unable to connect to real-time updates.';
        }
      });
    
    return () => {
      window.Echo.leave(`station.${stationId}`);
    };
  });
</script>

{#if connectionError}
  <div class="alert alert-error">{connectionError}</div>
{/if}
```

---

## Connection State Management

### Track Connection Status

```svelte
<script lang="ts">
  let isConnected = $state(false);
  let isConnecting = $state(true);
  
  $effect(() => {
    const pusher = window.Echo.connector.pusher;
    
    pusher.connection.bind('connected', () => {
      isConnected = true;
      isConnecting = false;
    });
    
    pusher.connection.bind('disconnected', () => {
      isConnected = false;
    });
    
    pusher.connection.bind('connecting', () => {
      isConnecting = true;
    });
    
    return () => {
      pusher.connection.unbind_all();
    };
  });
</script>

{#if !isConnected}
  <div class="alert alert-warning">
    {isConnecting ? 'Connecting...' : 'Disconnected. Trying to reconnect...'}
  </div>
{/if}
```

### Offline Banner Component

```svelte
<!-- resources/js/Components/OfflineBanner.svelte -->
<script lang="ts">
  let isOnline = $state(navigator.onLine);
  let wsConnected = $state(false);
  
  $effect(() => {
    const handleOnline = () => isOnline = true;
    const handleOffline = () => isOnline = false;
    
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    
    // Track WebSocket
    const pusher = window.Echo?.connector?.pusher;
    if (pusher) {
      pusher.connection.bind('connected', () => wsConnected = true);
      pusher.connection.bind('disconnected', () => wsConnected = false);
    }
    
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  });
</script>

{#if !isOnline}
  <div class="fixed top-0 left-0 right-0 bg-error text-error-content text-center py-2 z-50">
    No network connection
  </div>
{:else if !wsConnected}
  <div class="fixed top-0 left-0 right-0 bg-warning text-warning-content text-center py-2 z-50">
    Reconnecting to server...
  </div>
{/if}
```
