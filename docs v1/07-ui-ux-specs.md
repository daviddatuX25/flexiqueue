## UI / UX Specifications

**Purpose:** Describe the visual design system and detailed layouts for all core FlexiQueue screens so frontend development can proceed without constantly referring back to the thesis.

Related docs: `06-api-and-realtime.md`, `08-edge-cases.md`

---

### 1. Design System

#### 1.1 Colors

Primary palette (from architecture Section 15):

```text
Primary: #2563EB (blue)
Success: #16A34A (green)
Warning: #EA580C (orange)
Error:   #DC2626 (red)
Priority: #F59E0B (gold)
Gray:    #6B7280
White:   #FFFFFF
```

Usage:

- Primary blue: main CTAs, headers.
- Success green: confirmation actions, success messages.
- Warning orange: incomplete documents, caution states.
- Error red: blocking errors and invalid sequences.
- Priority gold: priority clients and track indicators.

#### 1.2 Typography

```text
Font: Inter, sans-serif

Sizes:
- 72px – large alias displays (e.g., “A1” on station/informant)
- 36px – main headings (screen titles)
- 24px – subheadings and key labels
- 16px – body text
```

#### 1.3 Spacing and Layout

- 4px base grid.
- Primary buttons: height ~ 80px on mobile.
- Secondary buttons: height ~ 48px.
- Card padding: 24px.
- Screen layouts are **mobile‑first** and scale up for larger screens.

---

### 2. Core Components

#### 2.1 Buttons

TailwindCSS‑style token definitions:

```css
Primary:
  bg-blue-600 text-white py-4 px-6 rounded-lg text-lg font-semibold

Secondary:
  bg-gray-200 text-gray-900 py-3 px-6 rounded-lg

Success:
  bg-green-600 text-white py-4 px-6 rounded-lg

Danger:
  bg-red-600 text-white py-3 px-6 rounded-lg
```

Interaction:

- Hover: slightly darker background.
- Active/pressed: subtle scale‑down or inset shadow.
- Disabled: reduced opacity and `cursor-not-allowed`.

#### 2.2 Cards

```css
Elevated:
  bg-white shadow-lg rounded-xl p-6

Status badge:
  px-3 py-1 rounded-full text-sm font-medium
```

#### 2.3 Inputs

```css
Text/Select:
  border border-gray-300 rounded-lg px-4 py-3 w-full

Textarea:
  border border-gray-300 rounded-lg px-4 py-3 min-h-24

Radio:
  w-5 h-5 text-blue-600
```

---

### 3. Screen 1 – Triage Interface

**Purpose:** Reception staff scans QR tokens, selects client category, and binds sessions.

Target layout: **mobile (375px width)**.

#### 3.1 Layout

- **Header (blue background, white text)**
  - App branding: “⚡ FlexiQueue | Triage”.
  - Current program: e.g., “Cash Assistance”.
  - Staff name + logout button (icon button).

- **Camera Section (~480px height)**
  - Large title: “📷 SCAN QR CODE”.
  - Centered camera viewfinder (square ~300x300).
  - Below viewfinder:
    - Small buttons: “Manual Entry” and “Recent”.

- **Category Selection (appears after successful scan)**
  - Text: “TOKEN SCANNED: A1” (36px, bold).
  - Text: “Select Client Category:” (24px).
  - Three large full‑width buttons (~120px height, 16px gap):
    1. Regular:
       - Label: “👤 REGULAR”.
       - Subtext: “Standard Processing”.
       - Style: white background, gray border.
    2. Priority:
       - Label: “⭐ PWD / SENIOR / PREGNANT”.
       - Subtext: “Express Lane”.
       - Style: gold background.
    3. Incomplete:
       - Label: “⚠️ INCOMPLETE DOCUMENTS”.
       - Subtext: “Legal Assistance Required”.
       - Style: orange background.

- **Bottom Action Row**
  - Left: secondary “Cancel” button (gray).
  - Right: primary “Confirm” button (green) – **disabled** until a category is selected.

- **Footer (gray bar)**
  - Info line: “🟢 Online | Queue: 12 | Processed: 45 | 15:34”.

#### 3.2 States

- **Default**
  - Camera active, no category section yet.
- **After Scan**
  - Category buttons visible.
  - Confirm enabled only when a category is chosen.
- **Error (e.g., double scan)**
  - Overlay modal showing details from `check-status` and Double Scan logic (see `08-edge-cases.md`).

---

### 4. Screen 2 – Station Interface

**Purpose:** Staff at a station call next clients, process them, and move them through the flow.

Target layout: **mobile (375px)**.

#### 4.1 Layout

- **Header**
  - Title: e.g., “Table 2 – Interview” (h1).
  - Staff name and menu icon (for settings/assignment).

- **Current Client Card (when serving)**
  - Label: “NOW SERVING” (gray).
  - Alias: large “A1” (72px, bold, centered).
  - Badge: “⭐ PWD / Senior” (gold badge).
  - Meta:
    - “🕐 Started: 10:35 AM”.
    - “⏱ Duration: 3m 45s”.
  - Progress bar:
    - Text: “Step 2 of 3: Interview”.
    - Visual: blue bar filled to ~67%.

- **Primary Action Button**
  - Large green button (~80px height).
  - Label: “✓ SEND TO CASHIER”.
  - Subtext: “(Table 4 – Next Step)”.

- **Secondary Actions Row**
  - Three equal buttons (~48px height):
    - “↻ Re‑queue”.
    - “⚠️ Override”.
    - “🎤 Talk” (post‑MVP audio feature).

- **No‑Show Button**
  - Gray button at bottom:
    - Label: “❌ Mark No‑Show (0/3)”.
    - Counter increments based on `no_show_attempts`.

- **Queue Preview**
  - Title: “QUEUE (Next 5):”.
  - List items: e.g., “1. B3  Regular  🕐 2m”.
  - “View All” button at bottom or inline link.

- **Footer**
  - Similar to triage (online status, queue count, processed count, time).

#### 4.2 Empty State

When no client is assigned to this station:

- Replace client card with centered message:
  - “NO CLIENT ACTIVE” (gray text).
- Large blue button (~100px):
  - Label: “🔔 CALL NEXT CLIENT”.
  - Subtext: “(B3 is ready)” or similar hint.

#### 4.3 Override Modal

When “⚠️ Override” is tapped:

- Centered white card (90% width, rounded corners).
- Title: “⚠️ OVERRIDE STANDARD FLOW”.
- Radio options:
  - `Table 1 – Verification`
  - `Table 3 – Legal Assistance`
  - `Table 4 – Cashier`
  - `Table 5 – Manager`
- Text area:
  - Label: “Reason (required):”.
  - Placeholder: “Explain why you are overriding the standard path…”.
- Buttons:
  - Left: “Cancel” (gray).
  - Right: “Confirm” (blue / green), disabled until both a target and reason are provided.

---

### 5. Screen 3 – Informant Display

**Purpose:** Waiting‑area screen where clients can check status and see who is being served.

Target layout: **portrait kiosk (768x1024)**.

#### 5.1 Layout

- **Header (blue)**
  - App name: “⚡ FlexiQueue”.
  - Program name: “Cash Assistance Distribution”.
  - Date (e.g., “February 10, 2026”).

- **Scan Section (~400px)**
  - Title: “🔍 CHECK YOUR STATUS”.
  - Large tap area with QR icon.
  - Text: “TAP TO SCAN QR CODE”.

- **After Scan (status view replaces scan section)**
  - Title: “YOUR STATUS:” (h2).
  - Alias: “A1” (72px, bold).
  - Badge: “⭐ Priority” (gold).
  - Progress list:
    - “✓ Triage – Complete”.
    - “→ Interview – IN PROGRESS” (blue, highlighted).
    - “○ Cashier – Waiting” (light gray).
  - Text: “📍 Currently at: Table 2”.
  - Text: “⏱ Wait time: ~5 minutes”.
  - Button: “✓ OK, GOT IT” (green).

- **Now Serving Section (always visible)**
  - Title: “🔔 NOW SERVING”.
  - 2x2 grid of cards:
    - Each card:
      - “A1 → Table 2”.
      - Subtext: “Interview”.

- **Waiting Area Summary**
  - Title: “⏳ CURRENTLY WAITING:”.
  - Rows per station:
    - E.g., “Table 1: A8, B5, C2 (3 clients)”.
  - Footer text: “Total in queue: 8”.

---

### 6. Screen 4 – Admin Dashboard

**Purpose:** Admins monitor system status and configure programs, tracks, stations, and devices.

Target layout: **desktop (1440px)**.

#### 6.1 Layout

- **Left Sidebar (240px, gray background)**
  - Logo at top.
  - Menu items:
    - “📊 Dashboard”.
    - “📋 Programs”.
    - “🖥️ Devices”.
    - “📈 Reports”.
    - “⚙️ Settings”.

- **Main Content Header**
  - Title: “⚡ FlexiQueue Admin Dashboard”.
  - User name + logout on the top right.

- **System Health Cards**
  - Row of 4 cards (white, centered content):
    - Example metrics:
      - “42” – Active Sessions.
      - “8” – Devices Online.
      - “12” – Queue Waiting.
      - “99.5%” – Uptime (today).

- **Active Program Section**
  - Title: “ACTIVE PROGRAM: Cash Assistance”.
  - Row list:
    - “Track A (Regular): 15 clients”.
    - “Track B (Priority): 8 clients ⭐”.
    - “Track C (Incomplete): 4 clients ⚠️”.

- **Station Status Table**
  - Columns:
    - Station.
    - Staff.
    - Queue length.
    - Devices status.
  - Example row:
    - “Table 1 (Verify) | Juan Cruz | 3 | 🟢🟢”.

- **Bottom Actions**
  - Buttons:
    - “+ Add Station”.
    - “⚠️ Configure Flow”.
    - “📊 Reports”.

#### 6.2 Device Manager Modal

When “🖥️ Devices” is opened:

- Full‑screen overlay with centered card.
- Title: “🖥️ HARDWARE DEVICES” + “+ Register New” button.
- Table with columns:
  - Device name.
  - MAC address.
  - Station.
  - Status (e.g., 🟢 Online / 🔴 Offline / ⚫ Disabled).
  - Actions (e.g., Reassign, Disable).
- Example row:
  - “ESP32‑Display‑1 | AA:BB:CC:DD:EE:01 | Table 1 | 🟢 Online”.
- Capability summary box at bottom:
  - “CAPABILITIES DETECTED (Table 1):”
  - “✓ Display (Portrait, 7")”.
  - “✓ Physical Buttons”.
  - “✗ Speaker – No speaker attached (audio announcements unavailable)”.

---

### 7. Interaction Notes

- All buttons:
  - Hover → darker shade.
  - Active → pressed effect.
- Modals:
  - Dark overlay (`bg-black/50`).
  - Centered card with close “X” or Cancel button.
- Loading states:
  - Use skeleton screens (animated gray bars) for lists and cards.
- Responsiveness:
  - Mobile‑first layouts stack vertically.
  - On wider screens, cards and lists expand horizontally with gutters.
- Icons:
  - Prefer simple emojis or lightweight SVGs for clarity and speed.

---

For underlying flows (state transitions and edge cases), see `05-flow-engine.md` and `08-edge-cases.md`. For the endpoints powering these screens, see `06-api-and-realtime.md`.

