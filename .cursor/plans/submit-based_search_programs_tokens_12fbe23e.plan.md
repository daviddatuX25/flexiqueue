---
name: Submit-based search Programs Tokens
overview: "Align Programs and Tokens pages with the Admin Clients search pattern: submit-based search (form submit with a Search/Apply button) instead of realtime/as-you-type filtering, to reduce listeners and improve behavior on older phones. Programs will get backend search support and a Clients-style form; Tokens already use apply-on-submit and will get UI/label consistency only."
todos: []
isProject: false
---

# Submit-Based Search: Programs and Tokens (Match Clients)

## Goal

- **Programs**: Remove realtime (as-you-type) search; use submit-based search like [resources/js/Pages/Admin/Clients/Index.svelte](resources/js/Pages/Admin/Clients/Index.svelte): form with a "Search" button, optional `?search=` in the URL, backend filters and returns the list.
- **Tokens**: Already submit-based (search and status are applied only when "Apply filters" is clicked). Ensure consistency with Clients (same pattern: no reactive filtering on input, only on submit).
- **Rationale**: Fewer listeners and no reactive updates on every keystroke, which is friendlier on older phones and matches the Clients pattern for modularity and consistency.

## Reference: Clients search pattern

- **Backend** [app/Http/Controllers/Admin/ClientPageController.php](app/Http/Controllers/Admin/ClientPageController.php): `index(Request $request)` reads `$request->query('search')`, filters `Client::query()` with `where('name', 'like', '%'.$search.'%')`, returns `clients` and `search` (the applied term) in Inertia props.
- **Frontend** [resources/js/Pages/Admin/Clients/Index.svelte](resources/js/Pages/Admin/Clients/Index.svelte): Props include `search: initialSearch`. Local state `searchTerm = $state(initialSearch ?? "")` bound to the input. **No** `$derived` that filters the list from `searchTerm`. Form `onsubmit` calls `router.visit("/admin/clients", { method: "get", data: { search: searchTerm.trim() || undefined }, preserveState: true, preserveScroll: true })`. List is the raw `clients` from props (already filtered by backend). Shows "Showing results for {initialSearch}" when `initialSearch` is set. Empty state when `clients.length === 0` (no matches or no data).

## 1. Programs page: switch to submit-based search

### Backend

- **File**: [app/Http/Controllers/Admin/ProgramPageController.php](app/Http/Controllers/Admin/ProgramPageController.php)
- In `index()`, accept an optional `search` query parameter (e.g. via `Request $request` and `$request->query('search', '')`).
- When `$search` is non-empty: filter `Program::query()` by name and description (e.g. `where(function ($q) use ($search) { $q->where('name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'); })`), case-insensitive via `like` and `%...%`.
- Pass `search` back in the Inertia payload (e.g. `'search' => $search !== '' ? $search : null`) so the frontend can show "Showing results for …" and prefill the input.
- Return the same program shape as today; only the set of rows is filtered.

### Frontend

- **File**: [resources/js/Pages/Admin/Programs/Index.svelte](resources/js/Pages/Admin/Programs/Index.svelte)
- **Props**: Add `search: initialSearch = ""` (or `null`) alongside `programs`. Use it to initialize the input and to decide when to show "Showing results for …".
- **Remove** the realtime filter: delete `filteredPrograms` and any `$derived` that depends on `searchQuery`. The list to render is always `programs` from props (already filtered by the server when `search` was provided).
- **State**: Keep a single source for the input: `searchTerm = $state(initialSearch ?? "")` (and optionally sync when `initialSearch` changes from the server, e.g. when navigating back with a search in the URL). Do **not** derive the displayed list from `searchTerm`; the list only changes after a form submit and a new page payload.
- **Form**: Wrap the search input in a `<form onsubmit={handleSearchSubmit}>` that prevents default and calls `router.visit("/admin/programs", { method: "get", data: { search: searchTerm.trim() || undefined }, preserveState: true, preserveScroll: true })`. Use the same route as the current programs index (no new route).
- **UI**: Match Clients: one search input, one **"Search"** button (not "Apply filters"). Optionally show a line like "Showing results for {initialSearch}" when `initialSearch` is set. Reuse the same join/input styling as Clients (search icon + input + button in one row).
- **Empty state**: When `programs.length === 0`: if `initialSearch` is set, show "No programs match your search" (and e.g. a "Clear search" that visits without `search`); otherwise keep the existing "Create your first program" state. No client-side filtering, so no need for a separate "filtered empty" branch based on a derived list.

Result: Programs search is submit-based only; no listeners that run on every keystroke; behavior and UX align with Clients.

## 2. Tokens page: confirm consistency (no auto search)

- **File**: [resources/js/Pages/Admin/Tokens/Index.svelte](resources/js/Pages/Admin/Tokens/Index.svelte)
- Tokens **already** use submit-based search: `searchQuery` is only sent when the user clicks "Apply filters" (or presses Enter in the search field, which calls `onFilterApply()`). The list is not derived from `searchQuery`; it comes from `fetchTokens()` which uses `buildTokensUrl()` (including `search` only at apply time). So there is no "auto" search to remove.
- **Optional consistency tweaks** (only if desired): Use a "Search" label for the search input (e.g. "Search by ID") and keep "Apply filters" for the button so it’s clear that both status and search are applied together; or rename the button to "Search" when the only control is search (but Tokens also have status filter, so "Apply filters" is still accurate). No change to when the request is sent (only on submit/apply).
- **Conclusion**: No mandatory code change for Tokens. At most, minor copy/label alignment with Clients; the important point is that Tokens do not perform search on every keystroke.

## 3. Summary


| Page     | Current behavior                              | Change                                                                                                                                             |
| -------- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Programs | Realtime: `$derived` filter on `searchQuery`  | Backend `?search=`; form submit with "Search"; list = `programs` from props. Remove `filteredPrograms` and bind-only updates that affect the list. |
| Tokens   | Submit only: search in API on "Apply filters" | None required; optionally align labels/copy with Clients.                                                                                          |


## Files to change

- [app/Http/Controllers/Admin/ProgramPageController.php](app/Http/Controllers/Admin/ProgramPageController.php): Add `Request $request`, read `search`, filter `Program` query, pass `search` in response.
- [resources/js/Pages/Admin/Programs/Index.svelte](resources/js/Pages/Admin/Programs/Index.svelte): Add `search: initialSearch` prop; remove `filteredPrograms` and realtime filtering; add form with `handleSearchSubmit` and `router.visit`; use `programs` from props for the list; optional "Showing results for" and empty state for no search results.
- [resources/js/Pages/Admin/Tokens/Index.svelte](resources/js/Pages/Admin/Tokens/Index.svelte): No change required for submit-only behavior; optional small label/copy tweaks for consistency.

## Edge cases

- **Programs**: Empty `search` param or missing param: show all programs (current behavior). After submit with empty input, pass `search: undefined` so the URL has no `search` and backend returns all.
- **Programs**: "Clear search" can be a link or button that does `router.visit("/admin/programs", { method: "get", data: {}, ... })` (or omit `search`).
- **Tokens**: No edge-case change; keep existing Enter-key and "Apply filters" behavior.

