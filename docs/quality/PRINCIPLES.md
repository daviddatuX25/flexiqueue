# FlexiQueue Architectural Principles

Reference for correct patterns per layer. Consult when making decisions.
Update only when a pattern decision changes — this file is stable.

---

## PHP / Laravel

### Controllers (`app/Http/Controllers/`)
- Receive the request, delegate to a service, return the response. Nothing else.
- No Eloquent queries. No `DB::table()`. No `Schema::` calls.
- No business logic or conditionals beyond choosing which service method to call.
- Inject services via constructor. One service call per action where possible.
- Data shaping (transforming models to JSON) → use Laravel API Resources (`app/Http/Resources/`).

### Services (`app/Services/`)
- Own all business logic and domain rules.
- May call other services (injected via constructor). May use Eloquent models.
- No HTTP-specific objects (`Request`, `Response`, `redirect()`).
- Side effects that don't belong here (e.g. file cleanup after deletion) → Listeners.

### Models (`app/Models/`)
- Define relationships, accessors, casts, and scopes. Nothing else.
- No file I/O (`Storage::`, `File::`) in `booted()` or observers → move to Listeners.
- No cross-row updates (updating sibling rows from inside the model) → move to Services.
- No DB queries inside instance methods → move to Services.
- 5+ accessor/getter methods for one concept → extract to a value object (`app/Support/`).

### Listeners / Events (`app/Listeners/`, `app/Events/`)
- Handle side effects triggered by model events (e.g. deleting TTS files when a model is deleted).
- Register via `$dispatchesEvents` on the model or in `AppServiceProvider`.

### Repositories (`app/Repositories/`)
- Wrap persistence concerns that don't belong in a model (e.g. singleton-style `getInstance()` patterns).
- One repository per model when needed.

---

## Svelte / Frontend

### Components (`resources/js/Components/`)
- One clear responsibility per component.
- Props flow down, events bubble up. No side effects from inside a component.
- No direct API calls (`fetch`, `axios`) — data comes from page-level props passed down.
- More than ~5 props is a signal to consider splitting the component.
- Duplicate logic between components → extract a shared component.

### Pages (`resources/js/Pages/`)
- Receive Inertia props from the controller. Pass relevant props down to components.
- Use `$page.props` only for shared data (auth user, flash messages).
- No raw `fetch()` calls — use Inertia `router` or `useForm()`.
- Logic that belongs in a child component → extract it.

### Inertia
- Use `useForm()` for all form submissions.
- Use `router.reload({ only: [...] })` for partial data refreshes — don't reload the full page.
- Do not pass the entire `$page.props` into components; be explicit about what each component needs.
