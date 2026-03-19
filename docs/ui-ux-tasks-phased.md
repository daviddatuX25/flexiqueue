Let me read the key pages first before suggesting anything.Good. I have enough. Let me also quickly check the remaining pages I haven't read yet.Good. I have a thorough read of all the key files. Now let me produce the organized suggestions.

---

# UI/UX Suggestions — Organized by Page/Location

Skipping all already-done items. Organized so your developer can work through one location at a time and you can verify per page.

---

## 🔐 Auth / Login (`Auth/Login.svelte`)

**Login — Back button** ✅ Already implemented — back button is present. Mark this done on the checklist.

**Password reset — Confirm + match + strength**
The profile password form has `passwordNew` and `passwordConfirm` fields but no live match indicator and no strength meter. On the profile page, add a small inline indicator below the confirm field: green checkmark + "Passwords match" when they match, red X + "Passwords don't match" while they differ. For strength, a simple 3-segment bar (weak/fair/strong) based on length + character variety is sufficient — no library needed, just a derived computed value. **Apply the same treatment to:** (1) Profile change-password form, (2) **Admin Staff “Reset password for user” modal** — that modal currently has only a single “New password” field; add confirm field, match indicator, and strength there too.

---

## 📱 Station Page (`Station/Index.svelte`)

This is the most dense page with the most open issues. Work through it in one pass.

**Waiting / On Hold — Mobile layout collapse**
On mobile, the On Hold and Waiting list items currently use a `flex-col` layout which stacks correctly, but the Resume/Start Serving/Cancel buttons appear below the badges without enough visual separation. Suggestion: wrap the alias + badges in a `div` and put the action buttons in their own `div` with `mt-2` and `w-full` on mobile. The "Start serving" + cancel button pair in Waiting should be `flex gap-2 w-full` on mobile with "Start serving" taking `flex-1` and cancel being icon-only (`btn-square`). This prevents the cancel X from being the same visual weight as the primary action.

**Waiting / On Hold — Badge cut-off**
The second-column badges (Regular, Priority, etc.) in the waiting and holding lists are getting clipped on narrow screens because the grid columns are fixed-width. Change `md:grid-cols-[minmax(0,3ch)_1fr_max-content_auto]` to `flex flex-col gap-1` on mobile (already there) but ensure the badge `div` has `flex-wrap` and `min-w-0` so badges wrap rather than overflow. The `minmax(0,3ch)` column for alias is fine — just make sure the badge column is `1fr` not `max-content` at mobile widths.

**Ongoing / Connected badges — Pulsating green**
The `CategoryBadge` component is used throughout. For "serving" status specifically, add a subtle pulsating green left border or a pulsating dot indicator to the token card header area (the `text-xs font-medium uppercase` label that says "Now Serving"). This can be done with a `relative` wrapper and an `animate-ping` span similar to what the homepage uses — a small green dot next to "Now Serving" text. Wider tap area is already handled by `touch-target-h` on the buttons — no change needed there.

**Now Serving / More button alignment**
The `More` button in the actions row is `md:hidden` (mobile only) and lives in `flex flex-wrap gap-2`. When the row has Hold + Back + More, if More wraps to a new line alone it leaves a gap. Change the actions row from `flex flex-wrap gap-2` to `grid grid-cols-3 gap-2` on mobile (Hold, Back, More each get one column, equal width, no orphan). On desktop the More button is hidden anyway so this doesn't affect desktop layout.

**Staff stats readability**
The stats footer row ("Today: X served · Avg X min") uses `text-surface-950/70` which can be hard to read in certain themes. Bump to `text-surface-950/90` and make the `strong` values use `text-primary-600` instead of `text-surface-950` to give them color contrast without being harsh.

---

## 👤 Profile Page (`Profile/Index.svelte`)

**Preset QR section layout**
Currently the QR code is shown inline in the profile card. The checklist asks for it to match the Program Overrides page layout: QR code on top, action table/buttons below. Suggestion: restructure the QR section as a two-part card — top half has the QR image centered at roughly 160×160px, bottom half has Print and Regenerate buttons in a `flex gap-3 justify-center` row. This matches the visual pattern used in `ProgramOverrides/Index.svelte`.

**Print / Regenerate buttons — Size standardization**
These are currently inconsistent with the rest of the profile page button sizing. Apply `btn-lg touch-target-h` to Print and Regenerate to match the section-level action size used elsewhere on the page (e.g. the Save password button).

**Password — Confirm + match + strength** ✅ Already implemented (Profile + Admin Staff reset).

---

## 🔑 PIN Input Component (`Components/PinOrQrInput.svelte`)

**PIN input — Full width on mobile**
The PIN input has `max-w-[8rem]` hardcoded. On mobile inside modals this feels cramped and is hard to tap accurately. Change to `w-full max-w-[8rem] sm:max-w-[8rem]` — actually just remove the `max-w-[8rem]` entirely for mobile and let it fill the container. The 6-digit mono input looks fine at full width. Or use `w-full md:max-w-[8rem]` so it's full width on mobile, constrained on desktop. Apply this everywhere `PinOrQrInput` is used — since it's a component, one change fixes all instances (Display Settings modal, Public Triage PIN, Profile PIN, Override modal, Force Complete modal).

**PIN input — Validate before revealing settings** ❌ Removed from scope. Desired behavior: show settings first, validate on save (no gate).

---

## 📊 Admin Programs Show (`Admin/Programs/Show.svelte`)

**Overview track flow cards**
The checklist asks to replace plain arrows with horizontally scrollable card layout matching the Track section. The Overview tab currently uses `FlowDiagram` component which renders as a static flow. Suggestion: add a horizontally scrollable `overflow-x-auto` row of mini step cards below the flow diagram — each card shows station name, process name, and estimated time. Use the same `rounded-container border border-surface-200` card style used in the Stations tab. The existing diagram stays as-is; the card row is an additional "quick view" below it.

**Tracks — Manage Steps: drag-and-drop**
Currently uses up/down arrow buttons. For mobile, integrate `@dnd-kit` or the simpler `svelte-dnd-action` library for touch-friendly drag-and-drop reordering. Keep up/down buttons as fallback for accessibility. The drag handle should be a `GripVertical` icon on the left of each step row.

**Tracks — Manage Steps: Add Step**
Currently a small text link. Change to a proper `btn preset-filled-primary-500 w-full` button at the bottom of the steps list in the modal, sized consistently with other create actions. Label: "Add step" with a `Plus` icon.

**Tracks — Card flow visualization**
Once the Overview track flow cards are updated (above), mirror the same card style in the Tracks tab so the two views feel consistent. Right now Tracks shows a flat list of steps; wrap each step in the same mini card style.

**Diagram — Sidebar width on mobile**
The diagram canvas left sidebar uses a fixed pixel width. Add a `w-[20%] min-w-[60px]` constraint on mobile breakpoints. The sidebar content (node labels) should truncate with `truncate` class and show full name on hover/tap via `title` attribute.

**Diagram — Station drag-and-drop onto canvas**
Currently stations are added via a sidebar button. Add a `draggable="true"` attribute to sidebar station items and a `dragover`/`drop` handler on the canvas area to place the node at the drop coordinates. This is a significant interaction improvement for the diagram builder.

**Standardize button sizes — Settings, Processes, Stations**
In the Settings tab: the save button is already `btn-lg`. In Processes: the Add Process button (now in modal) and inline edit/delete buttons should follow the rule — section-level actions are `btn` (default size), inline row actions are `btn-sm`. In Stations: the Generate TTS and Deactivate buttons are already fixed per the done items — verify the size rule is consistent with Processes.

---

## 🖥️ Display Board (`Display/Board.svelte`)

**Now Serving cards — Mobile horizontal scroll**
The Now Serving grid currently uses `flex-wrap` which causes wrapping on mobile. Change to `flex flex-nowrap overflow-x-auto gap-3 pb-2` with `snap-x snap-mandatory` and each card getting `snap-start shrink-0 w-[min(100%,280px)]`. This enforces single row horizontal scroll on mobile and full grid on desktop.

**Current serving rectangle cards — Same treatment**
Same as above — apply the same `flex flex-nowrap overflow-x-auto` treatment to the station activity/current serving section.

---

## 📋 Admin Programs Index / Misc Admin Pages

**Client list — Action buttons equal size on mobile**
In `Admin/Clients/Index.svelte`, the View Details and Delete buttons should be `flex-1` inside a `flex gap-2 w-full` wrapper on mobile so they're equal width. On desktop they can remain their natural size.

**Date range selector — Mobile expand/collapse**
In `Admin/Logs/Index.svelte` and `Admin/Analytics/Index.svelte`, the date range picker is wide and doesn't collapse well on mobile. Wrap it in a `details`/`summary` collapsible (same pattern as Station Notes) on mobile — show selected range as the summary label, expand to show the full picker.

**Staff section — Dropdown overflow**
In `Admin/Users/Index.svelte` or wherever the staff dropdown appears — the button text is overflowing the native `<select>` icon. Add `pr-8` (or `pr-10`) to the select element to give the native chevron icon clearance. This is a one-line CSS fix.

**Station assignment column — "Not assigned"**
In the staff/users table where station assignment is shown, replace the dash (`—`) with `<span class="text-xs text-surface-950/50">Not assigned</span>`. More informative, matches the checklist request.

**Client registration label**
In the triage/client registration flow, the button/heading that says "Create a client registration" should be changed to something that communicates the registration is immediate — suggest "Register client" or "Add client registration." Short and unambiguous.

**Add Staff modal — Reset password label**
In the Users admin modal, the "Change Password" action should be replaced with a key icon (`Key` from lucide-svelte, already imported in most admin pages) + "Reset" text. Use `btn-sm` with icon + label pattern consistent with other admin inline actions.

---

## 🖨️ Admin Tokens Print (`Admin/Tokens/Print.svelte`)

**Button sizing — standard**
On the token print page (`/admin/tokens/print`, e.g. `?cards_per_page=…`), ensure all toolbar and empty-state buttons use the **standard** size consistent with the rest of admin (e.g. `btn` for primary actions, not oversized or undersized). Back to Tokens, Print, Print instructions, and the empty-state "Go to Tokens" should match the app’s standard button sizing.

---

## ⚙️ Configuration page (ex–System Settings) + Programs / Tokens / Staff

**Rename System Settings → Configuration**
Rename the page and nav: route label, breadcrumb, and page title from "System Settings" to "Configuration."

**Move settings into Configuration as tabs**
Default program settings, Print settings, and Token TTS settings (and any similar “settings” modals) currently live as buttons/modals on Programs, Tokens, and Staff pages. Move each into the Configuration page as **tabs**. Remove the settings buttons from the top of those three pages. Configuration becomes the single place for these settings.

**Programs / Tokens / Staff — one header button; mobile FAB**
After the move, Programs, Tokens, and Staff each have **one** primary action button in the header. On **mobile**, that button must not be full-width: use an **absolute positioned circular icon-only** button at **bottom-right** of the viewport, with a small **bottom offset** so it sits above the footer. Desktop keeps the normal header button.

---

## 🌐 Global / Cross-cutting

**Image compression before upload**
Use a **compression library** (e.g. `browser-image-compression`) to ease implementation. **Modular:** one shared utility (e.g. `lib/imageUtils.js` or wrapper around the library) with options for max width/quality. Apply to: (1) **Profile** (main/avatar image), (2) **Site** hero/image uploads, (3) **Program** mini-blog/banner image uploads. Keep the API modular so different pages can pass different presets (e.g. avatar: max 800px, hero/banner: max 1200px).

**Centralize print settings**
Move the default print settings out of the token print flow. In `Admin/Tokens/Print.svelte`, replace the inline print settings section with a link/button: "Change print settings → go to Settings page." The Settings page already has a print settings section. This reduces duplication and makes print config a single source of truth.

**Password reset — Confirm + match + strength** Done (already implemented).
(N/A — already implemented.)

---

## 📋 Phasing Suggestion

Given these are all non-blocking polish items, suggest phasing them in this order for fastest visible impact:

**Phase 1 — Quick wins (one-liners or near one-liners):** ✅ Done
- Station assignment "Not assigned" text ✅
- Staff dropdown `pr-8` fix ✅
- Badge/stats text contrast bumps (Station stats footer) ✅
- PIN input `w-full` on mobile ✅
- Login back button ✅ (already implemented — marked done)
- Add Staff modal "Reset" label (PW → Reset) ✅

**Phase 2 — Mobile layout fixes (medium effort, high impact on phones):** ✅ Done
- Station waiting/holding mobile layout collapse ✅
- Now Serving horizontal scroll (Display Board) ✅
- Badge cut-off fix (Station) ✅
- More button grid fix (Station) ✅
- Date range collapse on mobile ✅ (already done)

**Phase 3 — Component & config consolidation:**

1. **Image compression (modular)**  
   Use a compression library (e.g. `browser-image-compression`). One shared modular utility; apply to Profile (avatar), Site hero/images, Program banner/mini-blog images. Presets: avatar ~800px, hero/banner ~1200px.

2. **Admin Tokens Print page — button sizing**  
   On `admin/tokens/print` (e.g. `?cards_per_page=…`), ensure all buttons use the **standard** size (same as rest of admin): primary actions = standard `btn`, secondary = consistent. No oversized or undersized buttons.

3. **Configuration page + move settings from Programs / Tokens / Staff**  
   - Rename **System Settings** → **Configuration** (route, nav label, page title).  
   - Move into Configuration as **tabs**: Default program settings, Print settings, Token TTS settings (and any other “settings” modals from those pages). Each becomes a tab on the Configuration page; remove the settings buttons from the top of Programs, Tokens, and Staff pages.  
   - After the move, Programs, Tokens, and Staff each have **one** primary action button in the header.  
   - **Mobile:** That one button must not be full-width. Use an **absolute positioned circular icon-only** button at **bottom-right** of the screen, with a small **bottom offset** so it sits above the footer. Desktop keeps the normal header button.

4. ~~PIN validation gate~~ Removed. ~~Password confirm/match/strength~~ Already done. ~~QR section layout on Profile~~ Dropped from Phase 3; can be reintroduced later if needed.

**Phase 4 — Larger interaction improvements:**
Tracks drag-and-drop steps, diagram sidebar mobile width, Overview track flow cards, print settings centralization.

**Phase 5 — Significant features:**
Diagram station drag-and-drop onto canvas (this is a full feature, not polish — defer to its own plan if needed).