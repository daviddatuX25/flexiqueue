# Program Diagram: State, Undo/Redo, and Layering — Research

This document captures research on **@xyflow/svelte** (Svelte Flow) and how to correctly implement **state ownership**, **undo/redo (Ctrl+Z)**, and **layering (bring to front / send to back)** in the program diagram. Use it as the single source of truth before implementing or changing these features.

---

## 1. Library and version

- **Package**: `@xyflow/svelte` (Svelte Flow)
- **Version in project**: `^1.5.1`
- **Docs**: https://svelteflow.dev  
- **API**: https://svelteflow.dev/api-reference

Svelte Flow is the Svelte port of React Flow (xyflow). It provides an interactive canvas with nodes and edges, viewport (pan/zoom), and internal state. There is **no built-in undo/redo**; we must implement it ourselves.

---

## 2. State ownership: two valid patterns

Svelte Flow allows two ways to own and update nodes/edges.

### Pattern A: Parent owns state with `bind:nodes` / `bind:edges` (recommended by docs)

From the [Svelte Flow API](https://svelteflow.dev/api-reference/svelte-flow) and [SvelteFlowProvider](https://svelteflow.dev/api-reference/svelte-flow-provider):

- The **parent** holds nodes and edges in **`$state.raw([])`** (or plain arrays).
- The flow is used with **`bind:nodes`** and **`bind:edges`** so the flow can read and **write back** when the user drags, selects, or deletes.

```svelte
<script>
  let nodes = $state.raw([...]);
  let edges = $state.raw([...]);
</script>
<SvelteFlowProvider>
  <SvelteFlow bind:nodes bind:edges ... />
</SvelteFlowProvider>
```

- **Undo/redo**: Keep a history of **plain snapshots** (e.g. `{ nodes: [...], edges: [...] }`) and on undo/redo **replace** the bound arrays: `nodes = clone(history[idx].nodes)`, `edges = clone(history[idx].edges)`.
- **Why `$state.raw`**: Docs recommend it for performance; it also avoids Svelte cloning the arrays on assignment, which can trigger DataCloneError if the array contained non-cloneable values (e.g. Map, functions).

**Pros**: Single source of truth in the parent; history is just plain data; no risk of cloning flow-internal objects.  
**Cons**: Parent must drive initial layout and any “load from API” by setting `nodes`/`edges`; the flow’s internal store is still used but is synced via the bindings.

### Pattern B: Flow owns state; parent uses hooks

- The **flow** owns state via its internal **bindable** props: `nodes = $bindable([])`, `edges = $bindable([])` (see `SvelteFlow.svelte`).
- When you pass **only** `nodes={someArray}` (one-way), the flow’s internal `nodes` is updated when the flow writes (e.g. drag, selection), but that write does **not** update the parent’s variable unless you used `bind:nodes`.
- With **SvelteFlowProvider**, child components use **useNodes()** / **useEdges()** / **useSvelteFlow()**. These hooks read/write the **flow’s store**, which is the flow’s internal state.

From the library source:

- `useNodes()` returns `{ current, set, update }` where `current` is `store.nodes` and `set(nodes)` does `store.nodes = nodes`.
- The store’s `get nodes()` returns the flow’s bindable value; `set nodes(v)` sets it. So when we call `setNodes(...)` we are updating the flow’s internal state.

**Undo/redo with Pattern B**:  
Keep history in **writable stores** (not `$state`), storing **only JSON-serializable snapshots** (e.g. `nodes` and `edges` from `svelteFlow.toObject()` after `JSON.parse(JSON.stringify(...))`, plus viewport and any waypoints as plain arrays). On undo/redo, call `setNodes(entry.nodes)`, `setEdges(entry.edges)`, `setViewport(entry.viewport)`.

**Critical**: Do **not** put history entries in Svelte `$state`. If the flow (or our code) ever puts non-cloneable values (Map, Set, functions, DOM) into something Svelte tracks with `$state`, assignment can throw **DataCloneError** (“object could not be cloned”). Keeping history in **writable stores** and only storing **plain objects/arrays** avoids that.

**Summary**:  
- **Pattern A**: Parent holds `$state.raw(nodes/edges)`, `bind:nodes` `bind:edges`, undo = replace those arrays from history.  
- **Pattern B**: Flow owns state; we use `useNodes`/`useEdges`/`useSvelteFlow`; history in writable stores with plain snapshots; undo = `setNodes`/`setEdges`/`setViewport` from history.

---

## 3. Serialization and `toObject()`

- **useSvelteFlow().toObject()** returns `{ nodes, edges, viewport }` — the current flow state.
- For history we must store **plain** data. Flow’s internal nodes/edges may contain non-serializable or non-cloneable fields (e.g. internal refs, measured dimensions). So:
  - Use **`JSON.parse(JSON.stringify(nodes))`** (or an equivalent that strips non-JSON fields) for the part we put in history.
  - Optionally store **viewport** and **edge waypoints** (e.g. as `Array<[string, {x,y}]>`) in the same entry so undo/redo restores pan/zoom and bend points.

Any custom data we add (e.g. `entityId`, `data.stationId`) is plain and safe to serialize.

---

## 4. When to push history

- After **user-driven mutations**: drag end, add node/edge, delete, reconnect, resize, move viewport (if we want viewport in history), edge waypoint change.
- **Not** when we are **restoring** from history (undo/redo) or when **loading layout from API**. Use a guard (e.g. `isRestoring` or “skip next push”) so that applying a history entry or initial layout does not push a new history entry.
- Defer the actual push (e.g. `queueMicrotask`) so that the flow has finished updating its internal state before we call `toObject()` and push.

---

## 5. Layering (bring to front / send to back)

### 5.1 Node `zIndex` and `zIndexMode`

- Each **Node** can have a **`zIndex`** (number). It is used for painting order.
- **SvelteFlow** supports a prop **`zIndexMode`** (from `@xyflow/system`):
  - **`'manual'`**: Only explicit `node.zIndex` is used; no automatic raising on selection.
  - **`'basic'`** (default): Automatic z-index for **selection** (e.g. selected node drawn on top).
  - **`'auto'`**: Automatic for selection **and** subflows.

So for **explicit** “bring to front” / “send to back” we must either:

- Use **`zIndexMode="manual"`** and set **`zIndex`** on nodes ourselves (e.g. bring selected to `maxZ + 1`, send to back to `0`), or  
- Keep default `zIndexMode` and still set **`zIndex`** per node; in non-manual modes the library may combine our zIndex with selection elevation — so for predictable “front/back” behavior, **`zIndexMode="manual"`** is the clear choice.

### 5.2 How to change a node’s z-index

- **useSvelteFlow().updateNode(id, update)** — use it to set `zIndex`:
  - Bring selected to front: e.g. `const maxZ = Math.max(0, ...nodes.map(n => n.zIndex ?? 0));` then for each selected id `updateNode(id, { zIndex: maxZ + 1 })`.
  - Send selected to back: `updateNode(id, { zIndex: 0 })` (or a fixed low value).

After changing z-index we should **push a history entry** so undo restores the previous order.

### 5.3 Selection and Front/Back buttons

- The flow stores selection on the **node** object: **`node.selected`** (and similarly for edges).
- **useNodes().current** (or the bound `nodes` array in Pattern A) is the reactive list; **selected nodes** are those with `node.selected === true`.
- For “bring to front / send to back” we need the **list of selected node ids**. Derive it from the current nodes, e.g. `nodes.filter(n => n.selected).map(n => n.id)`.
- Buttons should be **disabled** when no node is selected (`selectedNodeIds.length === 0`).
- If we use **Pattern A** with `bind:nodes`, selection updates are reflected in the bound `nodes`; if we use **Pattern B**, `useNodes().current` updates when the flow updates the store. In both cases, ensure no **layout effect** overwrites the whole `nodes` array (and thus clears selection) unless we are intentionally loading from API or restoring history. So: run layout/load only when **layoutFromApi** (or equivalent) actually changes, not on every entityLookups/staffList change.

---

## 6. Delete key and events

- **deleteKey**: Prop on SvelteFlow. Default in KeyHandler is `'Backspace'`. To support both Backspace and Delete, pass **`deleteKey={['Backspace', 'Delete']}`**.
- **onbeforedelete** / **ondelete**: Use **onbeforedelete** to push history **before** the flow removes the elements, then return `true` (or a resolving Promise) to allow deletion.

---

## 7. Keyboard shortcuts (Ctrl+Z / Ctrl+Y)

- Svelte Flow does **not** provide undo/redo. We implement it in the app.
- Use **`<svelte:window onkeydown={...}>`** (or a global key handler):
  - **Ctrl+Z** (or Cmd+Z): `undo()`.
  - **Ctrl+Shift+Z** or **Ctrl+Y** (or Cmd+…): `redo()`.
  - Call **`event.preventDefault()`** so the browser does not trigger its own undo.

---

## 8. Recommended implementation checklist

1. **State ownership**  
   - Choose **Pattern A** (parent `$state.raw` + `bind:nodes` `bind:edges`) or **Pattern B** (flow-owned state + useNodes/useEdges).  
   - If staying with Pattern B: keep history in **writable stores**; store only **plain serializable** snapshots; never put history in `$state`.

2. **Undo/redo**  
   - History = list of `{ nodes, edges, viewport?, edgeWaypoints? }` (plain data).  
   - Push after user mutations; skip push when restoring or loading layout.  
   - Defer push (e.g. queueMicrotask) after mutations so `toObject()` sees the latest state.  
   - Undo/redo: set `nodes`/`edges`/viewport/waypoints from the chosen history entry (and in Pattern B set `isRestoring` so the next push is skipped).

3. **Layering**  
   - Set **`zIndexMode="manual"`** if we want full control over “bring to front” / “send to back”.  
   - Use **updateNode(id, { zIndex })** for front/back; compute selected ids from current nodes; disable buttons when no selection.  
   - Push history after changing z-index.

4. **Selection**  
   - Derive selected node ids from the same nodes array the flow uses (bound or useNodes().current).  
   - Avoid overwriting that array from a layout effect except when layoutFromApi (or initial load) actually changes.

5. **Delete**  
   - Use **deleteKey={['Backspace', 'Delete']}** and **onbeforedelete** to push history then allow deletion.

6. **Save**  
   - Before calling **toObject()** for save, ensure we have the latest state (e.g. `await tick()` then `toObject()`). Sanitize payload (e.g. entityId, process_handle stationId/processId) as required by the backend.

---

## 9. References

- [Svelte Flow – Key concepts](https://svelteflow.dev/learn/getting-started/key-concepts)
- [Svelte Flow – SvelteFlow](https://svelteflow.dev/api-reference/svelte-flow) (bind:nodes, bind:edges, deleteKey, zIndexMode, onbeforedelete, etc.)
- [Svelte Flow – SvelteFlowProvider](https://svelteflow.dev/api-reference/svelte-flow-provider)
- [Svelte Flow – useNodes](https://svelteflow.dev/api-reference/hooks/use-nodes), [useEdges](https://svelteflow.dev/api-reference/hooks/use-edges), [useSvelteFlow](https://svelteflow.dev/api-reference/hooks/use-svelte-flow)
- [Svelte Flow – Node type](https://svelteflow.dev/api-reference/types/node) (zIndex, selected, etc.)
- [Svelte Flow – useStore](https://svelteflow.dev/api-reference/hooks/use-store) (advanced)
- @xyflow/system: `ZIndexMode = 'auto' | 'basic' | 'manual'`; `getElevatedEdgeZIndex`; node z-index calculation in `adoptUserNodes` / `calculateZ`
- Project: `resources/js/Components/ProgramDiagram/DiagramFlowContent.svelte`, `DiagramCanvas.svelte`

---

## 10. Implementation options (what to do next)

### Option 1: Stay with Pattern B (current), fix remaining issues

- **Keep**: useNodes/useEdges, writable stores for history, plain HistoryEntryPlain (no Map in stored data), queueMicrotask + isRestoring.
- **Verify**: canUndo/canRedo are derived from store subscriptions (`$historyStore`, `$historyIndexStore`) so buttons enable when history has more than one entry / index not at tip.
- **Fix selection for Front/Back**: Ensure selectedNodeIds is reactive to the same nodes the flow updates. If our layout effect runs too often and overwrites nodes (clearing selection), restrict it so it only runs when `layoutFromApi` identity changes (e.g. ref lastLoadedLayoutRef). Ensure we do not setNodes/setEdges in an effect that depends on entityLookups/staffList if that would clear selection.
- **Layering**: Set **zIndexMode="manual"** on SvelteFlow so our updateNode(id, { zIndex }) is respected. Then bring to front = set zIndex above current max; send to back = set zIndex to 0; push history after.

### Option 2: Migrate to Pattern A (bind:nodes / bind:edges)

- **DiagramCanvas** (or DiagramFlowContent) holds `let nodes = $state.raw([])`, `let edges = $state.raw([])`.
- **SvelteFlow** is used with **bind:nodes** and **bind:edges** (and optionally bind:viewport).
- **Load from API**: set `nodes = sanitizedNodes`, `edges = sanitizedEdges` (and viewport) when layoutFromApi arrives; then push initial snapshot to history.
- **Undo/redo**: Replace state from history: `nodes = clone(entry.nodes)`, `edges = clone(entry.edges)` (and viewport/waypoints). History stays in writable stores; entries are plain objects.
- **Track edges**: Edges for the selected track can be computed in a derived and then set into `edges` when they change, or merged with user edges depending on product rules.
- **Pros**: Single source of truth; no DataCloneError from flow internals; undo is a simple assignment. **Cons**: More refactor; need to ensure track-derived edges and waypoints are merged correctly with bound edges.

Use this research doc as the contract for either option; implement one, then test undo, redo, front/back, delete, and save end-to-end.
