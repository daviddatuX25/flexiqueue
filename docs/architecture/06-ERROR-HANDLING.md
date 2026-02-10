# FlexiQueue — Phase 1 Error Handling Patterns

**Purpose:** Define consistent error handling patterns for the execution agent to follow across all controllers, services, and frontend components.

This file is referenced by `agentic-workflow.mdc` (line 100). Every controller and service MUST follow these patterns.

---

## 1. Error Response Format (API)

All error responses follow this JSON structure:

```json
{
  "message": "Human-readable description of what went wrong.",
  "errors": {
    "field_name": ["Specific validation error for this field."]
  },
  "code": "MACHINE_READABLE_ERROR_CODE"
}
```

- `message` — always present, suitable for displaying to users.
- `errors` — present only for validation errors (422). Maps field names to arrays of error strings.
- `code` — optional machine-readable code for frontend conditional logic.

---

## 2. HTTP Status Code Usage

| Code | When to Use | Example |
|------|------------|---------|
| **200** | Successful read or mutation | GET queue, POST transfer |
| **201** | Resource created | POST bind (new session), POST create program |
| **302** | Redirect after form submission | POST login → redirect to dashboard |
| **400** | Bad request (missing precondition) | "No active program" when trying to bind |
| **401** | Not authenticated, or invalid PIN | Invalid supervisor PIN on override |
| **403** | Authenticated but wrong role/scope | Staff trying to access admin route, or wrong station |
| **404** | Resource not found | Token QR hash doesn't match any record |
| **409** | Business logic conflict | Token already in_use, session already completed, duplicate alias |
| **422** | Validation error (Laravel default) | Missing required field, invalid format |
| **429** | Rate limited | Too many PIN attempts, too many login attempts |
| **500** | Unhandled server error | Unexpected exception (should be rare) |

---

## 3. Exception Classes

Create these custom exception classes in `app/Exceptions/`:

### 3.1 `BusinessException` (Base)

```php
namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;

    public function __construct(string $message, string $errorCode = 'BUSINESS_ERROR', int $statusCode = 409)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }

    public function render($request)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
                'code' => $this->errorCode,
            ], $this->statusCode);
        }

        // For Inertia requests, flash error and redirect back
        return back()->with('error', $this->getMessage());
    }
}
```

### 3.2 Specific Exceptions

| Exception Class | Status | Code | When |
|----------------|--------|------|------|
| `TokenInUseException` | 409 | `TOKEN_IN_USE` | Bind attempt on token with active session |
| `TokenUnavailableException` | 409 | `TOKEN_UNAVAILABLE` | Bind on lost/damaged token |
| `SessionTerminalException` | 409 | `SESSION_TERMINAL` | Action on completed/cancelled/no_show session |
| `SessionNotServingException` | 409 | `SESSION_NOT_SERVING` | Transfer/complete on non-serving session |
| `RequiredStepsRemaining` | 409 | `STEPS_REMAINING` | Complete when required steps not done |
| `InvalidSequenceException` | 409 | `INVALID_SEQUENCE` | Process skipper detected |
| `InvalidPinException` | 401 | `INVALID_PIN` | Wrong supervisor PIN |
| `NoProgramActiveException` | 400 | `NO_ACTIVE_PROGRAM` | Operations requiring active program when none exists |
| `StationAccessDeniedException` | 403 | `STATION_ACCESS_DENIED` | Staff accessing another station's data |

All extend `BusinessException`.

---

## 4. Error Handling in Controllers

### 4.1 Pattern: Let Exceptions Bubble

Controllers should NOT try-catch business exceptions. Let them propagate to the exception handler.

```php
// GOOD — clean, lets BusinessException handle rendering
public function transfer(Session $session, TransferRequest $request)
{
    $result = $this->sessionService->transfer(
        $session,
        $request->validated('mode'),
        $request->validated('target_station_id')
    );

    return response()->json(['session' => $result]);
}

// BAD — unnecessary try-catch wrapping
public function transfer(Session $session, TransferRequest $request)
{
    try {
        $result = $this->sessionService->transfer(...);
        return response()->json(['session' => $result]);
    } catch (SessionNotServingException $e) {
        return response()->json(['error' => $e->getMessage()], 409);
    }
}
```

### 4.2 Pattern: Validate Early

Use Laravel Form Requests for input validation. Business rules go in the service layer.

```php
// TransferRequest.php — validates input shape
public function rules(): array
{
    return [
        'mode' => ['required', 'in:standard,custom'],
        'target_station_id' => ['required_if:mode,custom', 'exists:stations,id'],
    ];
}

// SessionService::transfer() — validates business rules
// Throws SessionNotServingException if session.status != 'serving'
// Throws NoProgramActiveException if no active program
```

---

## 5. Error Handling in Services

### 5.1 Pattern: Throw Specific Exceptions

Services throw specific `BusinessException` subclasses with clear messages.

```php
// Per 03-FLOW-ENGINE.md: validate session state before transfer
if ($session->status !== 'serving') {
    throw new SessionNotServingException(
        "Session {$session->alias} is not currently being served. Cannot transfer."
    );
}

// Per 04-DATA-MODEL.md: token-session binding invariant
if ($token->status === 'in_use') {
    throw new TokenInUseException(
        "Token {$token->physical_id} is already in use.",
    );
}
```

### 5.2 Pattern: Database Transactions

Wrap multi-step mutations in DB transactions. If any step fails, everything rolls back.

```php
// Per 03-FLOW-ENGINE.md Section 3.1: bind is multi-step
return DB::transaction(function () use ($token, $track, $category) {
    $session = Session::create([...]);
    $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);
    $this->auditLogger->logTransaction($session, 'bind', [...]);
    event(new ClientArrived($session));

    return $session;
});
```

If the TransactionLog insert fails, the session creation and token update are both rolled back.

---

## 6. Error Handling on Frontend (Svelte)

### 6.1 Inertia Form Errors

Inertia automatically passes validation errors (422) to the page component.

```svelte
<script>
  import { useForm } from '@inertiajs/svelte';

  const form = useForm({ qr_hash: '', track_id: null, client_category: '' });

  function submit() {
    $form.post('/api/sessions/bind', {
      onError: (errors) => {
        // errors = { qr_hash: ['Token not found.'], ... }
        // Automatically available as $form.errors
      }
    });
  }
</script>

{#if $form.errors.qr_hash}
  <p class="text-red-600 text-sm">{$form.errors.qr_hash}</p>
{/if}
```

### 6.2 Business Error Handling (409, 400, etc.)

For non-validation errors, use the toast notification system:

```svelte
import { addToast } from '$lib/stores/toast';

function handleTransfer() {
  form.post(`/api/sessions/${session.id}/transfer`, {
    onError: (errors) => {
      // 422 validation → handled by form.errors
    },
    onFinish: () => {
      // Check for flash error from Inertia redirect
    }
  });
}

// For direct API calls (not Inertia):
async function callApi() {
  try {
    const response = await fetch('/api/sessions/101/transfer', { ... });
    if (!response.ok) {
      const data = await response.json();
      addToast({ type: 'error', message: data.message });
    }
  } catch (error) {
    addToast({ type: 'error', message: 'Network error. Please try again.' });
  }
}
```

### 6.3 Specific UI Error States

| Error | UI Treatment |
|-------|-------------|
| 409 Token in use | `DoubleScanModal` with session details |
| 409 Token lost/damaged | Red error banner: "Token marked as LOST. Use a different token." |
| 409 Invalid sequence | Full-screen red `InvalidSequenceScreen` |
| 401 Invalid PIN | Inline error in PIN modal: "Invalid PIN. Try again." |
| 403 Wrong station | Redirect to assigned station with toast |
| 400 No active program | Full-page message: "No active program. Contact admin." |
| Network error | Toast: "Network error. Please try again." |
| Offline | `OfflineBanner` component (persistent until reconnect) |

---

## 7. Logging & Monitoring

### 7.1 Application Logging

Use Laravel's built-in logging for server-side errors:

```php
// For unexpected errors (500s), log full context
Log::error('Unexpected error during session transfer', [
    'session_id' => $session->id,
    'user_id' => auth()->id(),
    'exception' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
]);

// For business errors, log at info level (expected behavior)
Log::info('Token bind rejected: token in use', [
    'token_id' => $token->id,
    'existing_session_id' => $token->current_session_id,
]);
```

### 7.2 Log Channels

- **Default channel:** `daily` (rotating file, 7 days retention).
- **Path:** `storage/logs/laravel-*.log`.
- **Production errors:** visible on admin dashboard as system health alerts (Phase 2 enhancement).

---

## 8. Error Pages (Inertia)

Create custom error pages in `resources/js/Pages/Errors/`:

| Page | Route | Message |
|------|-------|---------|
| `403.svelte` | Auto (Laravel) | "You don't have permission to access this page." + link to dashboard |
| `404.svelte` | Auto (Laravel) | "Page not found." + link to dashboard |
| `419.svelte` | Auto (Laravel) | "Session expired. Please log in again." + link to login |
| `500.svelte` | Auto (Laravel) | "Something went wrong. Please try again." + link to dashboard |

These are rendered automatically by Inertia when Laravel returns the corresponding status code for page requests (not API calls).

---

## 9. Developer Checklist

When implementing any endpoint or service method:

- [ ] Input validated via Form Request (422 on invalid input).
- [ ] Business rules throw specific `BusinessException` subclass (not generic Exception).
- [ ] Multi-step mutations wrapped in `DB::transaction()`.
- [ ] Error messages are human-readable and specific (not "Something went wrong").
- [ ] Error codes are machine-readable for frontend conditional logic.
- [ ] Frontend handles the error with appropriate UI (toast, modal, redirect, or inline message).
- [ ] Unexpected errors logged with full context (session_id, user_id, trace).
- [ ] No swallowed exceptions (empty catch blocks are forbidden).
