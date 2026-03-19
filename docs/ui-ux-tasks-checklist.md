### UI/UX Tasks Checklist

**This session** (parallel tasks on one page)

| Task | Status |
|------|--------|
| **Sticky header top margin** — Reduce so it just clears the top bar, not a big visible gap. | Done |
| **Sticky tab navigation** — Reduce top margin (Overview, Processes, Stations, etc.) so no large gap above top bar. | Done |
| **Tracks – Scroll arrows (light mode)** — Green chevrons + light shadow on tab nav. | Done |
| **Processes – Title/description** — Remove dash; description below title in smaller text. | Done |
| **Processes – Add Process** — Open in modal consistent with Add Station / other CRUD. | Done |

---

**Summary**

- **Total tasks**: 68  
- **Done**: 20  
- **Bugs**: 16  
- **UX polish**: 43  
- **Copy / Perf**: 5  

---

### Performance

- [ ] **Image compression on frontend** — Use a library (e.g. `browser-image-compression`); modular utility for Profile (avatar), Site hero/images, Program banner/mini-blog. Presets: avatar ~800px, hero/banner ~1200px.

---

### UX Polish

- [x] **Consistent margins** — Ensure proper consistent margin across all pages.
- [x] **Logout modal confirmation** — Add confirm step before logging out.
- [ ] **Standardize button sizes** — Title/description area = large buttons; inline actions = smaller. Apply to Settings, Processes, and Stations pages.
- [x] **Modal close buttons** — Remove background color on close (X) buttons in modals; test gray removal on light mode.
- [x] **Sticky header top margin** — Reduce so it just clears the top bar, not a big visible gap.
- [ ] **Marquee interaction** — Make marquee interruptible/scrollable by touch.
- [ ] **Waiting/On Hold (mobile)** — Collapse layout so Start Serving and Close buttons appear below the token card; avoid cutting off badges.
- [ ] **Ongoing/Connected badges** — Make wider for easier tap and add more visible pulsating green color.
- [x] **Token card icon buttons** — When buttons overflow to next row, remaining buttons should fill full row width (flex/grid).
- [x] **Sticky tab navigation** — Reduce top margin (Overview, Processes, Stations, etc.) so no large gap above top bar.
- [ ] **Overview track flow cards** — Replace plain arrows with horizontally scrollable card layout; visually match actual Track section card style.
- [x] **Processes – Add Process** — Open in a modal consistent with other CRUD creation flows.
- [x] **Processes – Title/description** — Remove dash separator; move description below title in smaller text.
- [x] **Stations – Volume/Edit/Delete buttons** — Use same grid treatment as other actions; predictable wrapping with full-width fill when needed.
- [x] **Stations – Card opacity on mobile** — Add opacity to station card on mobile.
- [ ] **Tracks – Manage Steps: drag-and-drop** — Replace up/down order buttons with a drag-and-drop library on mobile.
- [ ] **Tracks – Manage Steps: Add Step layout** — Make Add Step a modal-within-modal or clearly structured section (not a tiny text link).
- [ ] **Tracks – Card flow visualization** — Match layout with updated Overview track flow for consistency.
- [ ] **Diagram – Sidebar width (mobile)** — Left sidebar should shrink to ~20% of canvas width on mobile.
- [ ] **Diagram – Track labels (mobile)** — Very small labels, stacked in one line, pinned to very top of canvas.
- [ ] **Diagram – Station drag-and-drop** — Allow dragging station nodes directly onto canvas UI (not only from sidebar).
- [ ] **Diagram – Station routing section** — Add toggleable/collapsible routing section; collapsed shows routing type only, expanded shows full details.
- [ ] **Client list – Action buttons** — On mobile, make View Details and Delete buttons equal size.
- [x] **Client detail – Card spacing** — Client Details card has too much white space; tighten padding or adjust opacity.
- [ ] **ID Documents – Button size** — Reduce or standardize button sizes to match the rest of the app.
- [ ] **Date range selector (mobile)** — Make mobile-friendly with expand/collapse toggle similar to filter section.
- [x] **Selected date range button** — Change from box/background highlight to green text-only style (no colored background).
- [ ] **Now Serving cards (mobile)** — Enforce single row, horizontally scrollable (no wrapping).
- [ ] **Current serving rectangle cards (mobile)** — Same as Now Serving: single row, horizontally scrollable.
- [ ] **Marquee reliability** — Ensure marquee can always be scrolled/interrupted reliably.
- [x] **Admin role indicator** — Clicking indicator (top-left or in modal) should redirect to profile page, or remove redundant sidebar profile link on desktop admin view.
- [x] **Sidebar user menu (admin)** — Profile and Log out in drop-up when clicking profile/avatar in left navigation; click outside or Escape closes.
- [ ] **Preset QR section layout** — Match Program Override page layout: QR code + table below; universalize across triage and profile QR views.
- [x] **Profile header padding** — In marquee name/profile header, reduce padding-x to equal padding-y.
- [ ] **Print / Regenerate Preset QR buttons** — Apply same button size standardization rule to match rest of page.
- [ ] **Login – Back button** — Add a back button on the login page (e.g. to return to public/home).
- [x] **Login – Background** — Improve or standardize the login page background (visual treatment).
- [x] **Scrollbar always visible** — Keep scrollbar always on the side (e.g. overflow-y: scroll or overlay scrollbar) for consistent layout.
- [x] **Footer – Program status spacing** — Ensure program status in footer has plenty of space or padding; double-check layout.
- [x] **View program vs Manage program** — Make “View program” mean the same action as manage, or rename “View program” to “Manage program” for consistency.
- [x] **Tables – Pagination** — Add pagination to tables that list many items (e.g. tokens, clients, staff). _(Tokens list done; clients/staff when applicable.)_
- [x] **Password reset – Confirm + match + strength** — Add confirm password field, live match indicator (e.g. “Passwords match” / “Passwords don’t match”), and strength bar (weak/fair/strong). Applies to: **Profile** change-password form; **Admin Staff** “Reset password for user” modal (same treatment in both).
- [x] **Nav title ↔ page title consistency** — Align sidebar/nav labels with page titles (e.g. “System” in nav should match “System settings” on the page, or vice versa).
- [x] **Badges / status readability** — Review badges and status labels (e.g. active, availability). Ensure good readability: if text is black/dark, use a lighter hue for the badge background.
- [ ] **Centralize print settings** — Superseded by Phase 3: move Print settings (and Default program, Token TTS) into Configuration page as tabs; see `ui-ux-tasks-phased.md` Phase 3.
- [ ] **Admin Tokens Print — button sizing** — On `/admin/tokens/print`, use standard button size (same as rest of admin) for Back, Print, Print instructions, empty-state CTA.
- [ ] **Configuration page (ex–System Settings)** — Rename "System Settings" → "Configuration". Move Default program settings, Print settings, Token TTS settings from Programs/Tokens/Staff into Configuration as tabs. Programs/Tokens/Staff: one header button; on mobile, that button = absolute bottom-right circular icon with bottom offset above footer.

---

### Bugs

- [x] **Sidebar footer overlap** — Add padding/margin so sidebar content doesn’t overlap footer (mobile and admin desktop).
- [ ] **PIN input (Display Settings & Public Triage)** — Must fill full width on mobile.
- [x] **Theme toggle button** — Remove border-color flash on click; no border interaction on that button.
- [ ] **Waiting/On Hold (mobile badges)** — Badges in second column (Regular, Priority, etc.) are cut off; fix layout.
- [ ] **Staff stats readability** — Staff Online/Available/Assigned stats: fix unreadable gray text and inconsistent layout.
- [x] **Sidebar gray tint** — Investigate and fix gray tint on collapsed/expandable sidebar.
- [ ] **Now Serving token card – More button** — Fix flex so More button aligns properly with no blank space on right.
- [x] **Stations – Generate TTS / Deactivate (mobile)** — On mobile, organize buttons into one row and fill full width if they wrap (use grid).
- [x] **Stations – Window title (mobile)** — Window title not visible on mobile; address crowding (font size, badge placement, or marquee).
- [ ] **Staff section – Dropdown overflow** — Dropdown button text overflows and covers native dropdown icon; fix with padding-right or clipping left side.
- [x] **Tracks – Scroll arrows (light mode)** — Horizontal scrollable nav arrows too light; use green or brown chevrons with light shadow.
- [ ] **Tracks – Manage Steps: Add Step button** — Increase size/visual weight; should feel like a section-level control.
- [x] **Client detail – Date fields overlap** — Date Created and time overlap; use column flex (single field per row). Same for Birth Year.
- [x] **Chart text contrast** — Gray labels on light-blue background are unreadable; increase contrast.
- [ ] **PIN input – Validation** — Not doing: keep "show settings then validate on save" (no gate). Removed from scope.
- [ ] **PIN input – Full width (mobile)** — Ensure PIN input fills full width on mobile (especially 6-digit input).
- [ ] **PIN input – Additional full-width instance** — Apply same full-width-on-mobile rule anywhere else PIN input appears.

---

### Copy / Labels

- [x] **Login label** — Change login screen label from “Staff Login” to “Login”.
- [ ] **Client registration label** — Fix misleading “Create a client registration”; clarify that registration is created immediately (no Accept/Reject step).
- [ ] **Station assignment column** — When no station is assigned, show “Not assigned” (small text) instead of a dash.
- [ ] **Add Staff modal** — Replace “Change Password” label with key icon + “Reset” text.
- [ ] **(Perf/copy overlap)** Ensure any copy changes associated with performance tweaks are clear and concise (e.g., image compression notices if surfaced to user).

---

### Notes / No-Change Items

- [ ] **Card view on mobile** — Card view on mobile is fine; no changes needed (keep verifying as other changes land).
- [ ] **System and Integrations pages** — No issues noted; keep as-is.
- [ ] **Marquee on title layout flash** — Brief flash on short text acceptable for now; no action unless regressions appear.

