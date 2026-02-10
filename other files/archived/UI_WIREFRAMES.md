# FlexiQueue UI Wireframes & Mockups

This document contains the visual mockups for the three primary interfaces in FlexiQueue: QPD (Queue Processing), QRD (Queue Routing), and QID (Queue Informant).

---

## 1. QPD Interface (Queue Processing Device)

**Context:** Queue Processing Dashboard. This interface allows processing staff to control the physical flow of clients through their assigned table.

**Primary Functions:**
- Display client queue for current table
- Track total clients vs. served clients
- Control stub movement (move forward, requeue, redirect)
- Monitor performance index
- Scan and manage current client

### Visual Layout

```
+-----------------------------------------------------------------------+
|  FlexiQueue              Dashboard   Program   Logs   [Staff Name]   |
+-----------------------------------------------------------------------+
|                                                                       |
|  Feeding Program 2025              |                                  |
|  Table 2 - Verification            |   Enroute to:                    |
|                                    |                                  |
|  +-------------+  +-------------+  |                                  |
|  | 100         |  | 25          |  |   +----------+  +----------+     |
|  | Clients Tot |  | Served      |  |   | Move     |  | Move to  |     |
|  +-------------+  +-------------+  |   | Forward  |  | Next     |     |
|                                    |   | (→)      |  | Process  |     |
|  Performance Index                 |   +----------+  +----------+     |
|  +------------------------------+  |                                  |
|  | ████░░░░░░░░░░░░░░░░░░░░░  |  |   +----------+  +----------+     |
|  | 75% Efficiency               |  |   | Requeue  |  | Custom   |     |
|  +------------------------------+  |   | (↺)      |  | Route    |     |
|                                    |   +----------+  +----------+     |
|  Current Service Time: 12 mins     |                                  |
|  Queue Length: 5 waiting           |   +--------------------------+   |
|                                    |   | [Scanning Area]          |   |
|  [Scan or Input Below]             |   | Ready to scan QR code    |   |
|  [ SCAN QR CODE ] [ Input _____ ]  |   +--------------------------+   |
|                                    |                                  |
+-----------------------------------------------------------------------+

DETAILS PANEL (Right Side):
Current Client:
  - Alias: Green Owl 42
  - Current Process: Interview (Step 2 of 4)
  - Time In Process: 5 mins
  - Notes: Standard priority

Queue Preview:
  1. Blue Cat 11   - Waiting (Interview)
  2. Red Fox 08    - Waiting (Interview)
  3. Yellow Dog 33 - Waiting (Verification)
  4. Purple Bird 7 - Waiting (Interview)
  5. Orange Fish 2 - Waiting (Verification)

+-----------------------------------------------------------------------+
```

### UI Components

| Element | Description |
|---------|-------------|
| **Client Stats** | Total clients vs. Served clients (large, easy-to-read numbers) |
| **Performance Index** | Visual bar chart showing table efficiency % |
| **Action Buttons** | Move Forward, Move to Next Process, Requeue, Custom Route |
| **Scanning Area** | QR code scanner or manual input field |
| **Queue Preview** | List of next 5 clients waiting |
| **Current Client Info** | Large display of current client alias and process |

### Interaction Flow

1. Staff scans stub QR code or types alias
2. System displays current client details
3. Staff selects action: "Move Forward" → marks process complete → next process suggested
4. If manual routing needed: "Move to Next Process" → system shows available processes
5. "Requeue" → puts client back in queue for same process
6. "Custom Route" → override default flow with reason logging

---

## 2. QRD Interface (Queue Routing Device)

**Context:** Queue Routing Dashboard. This interface allows routing staff at program intake to categorize clients and assign initial routing/processes.

**Primary Functions:**
- Register new stubs or confirm pre-registered stubs
- Assign priority level (Normal, PWD, Senior, etc.)
- Select initial routing path (Standard vs. Custom route)
- Assign stub to initial table
- Monitor total intake

### Visual Layout

```
+-----------------------------------------------------------------------+
|  FlexiQueue              Dashboard   Program   Logs   [Staff Name]   |
+-----------------------------------------------------------------------+
|                                                                       |
|  Feeding Program 2025              |                                  |
|  Intake Station                    |   Enroute to:                    |
|                                    |                                  |
|  +-------------+  +-------------+  |                                  |
|  | 100         |  | 25          |  |   +----------+  +----------+     |
|  | Clients Tot |  | Registered  |  |   | Standard |  | Custom   |     |
|  +-------------+  +-------------+  |   | Route    |  | Route    |     |
|                                    |   +----------+  +----------+     |
|  Performance Index                 |                                  |
|  +------------------------------+  |   Priority Level:                |
|  | ████░░░░░░░░░░░░░░░░░░░░░  |  |                                  |
|  | 75% Throughput               |  |   +----------+  +----------+     |
|  +------------------------------+  |   | Normal   |  | PWD      |     |
|                                    |   +----------+  +----------+     |
|  Avg Registration Time: 3 mins     |                                  |
|  Current Queue: 8 waiting          |   +----------+  +----------+     |
|                                    |   | Senior   |  | Priority |     |
|  [Scan or Input Below]             |   +----------+  +----------+     |
|  [ SCAN QR CODE ] [ Input _____ ]  |                                  |
|                                    |   +--------------------------+   |
|                                    |   | [Scanning Area]          |   |
|                                    |   | Ready to scan QR code    |   |
|                                    |   +--------------------------+   |
|                                    |                                  |
|                                    |   [CONFIRM REGISTRATION]         |
|                                    |                                  |
+-----------------------------------------------------------------------+

REGISTRATION DETAILS:
Selected Routing: Standard Route
Selected Priority: Normal
Assigned Table: Table 1 (Verification)
Ready to Proceed? [YES] [NO]

+-----------------------------------------------------------------------+
```

### UI Components

| Element | Description |
|---------|-------------|
| **Client Stats** | Total clients intake vs. Registered (cumulative) |
| **Performance Index** | Throughput % - speed of intake process |
| **Route Selection** | Standard vs. Custom route buttons |
| **Priority Buttons** | Normal, PWD, Senior, Priority (quick classification) |
| **Scanning Area** | QR code scanner or manual input field |
| **Confirmation Panel** | Shows selected routing, priority, assigned table |
| **Confirm Button** | Finalizes registration and sends stub to assigned table |

### Interaction Flow

1. Client arrives at intake
2. Routing staff scans stub QR code or manually enters alias
3. System checks: Is stub already registered? If yes → confirm. If no → register new
4. Staff selects priority level (Normal/PWD/Senior)
5. Staff selects routing path (Standard/Custom)
6. System auto-assigns to least-busy table capable of first process
7. Staff reviews assignment on confirmation panel
8. Staff clicks "CONFIRM REGISTRATION" → stub routed to assigned table
9. Client receives printed receipt with stub alias and next steps

---

## 3. QID Interface (Queue Informant Device)

**Context:** Queue Information Display. This client-facing interface shows current status, process workflow, and estimated wait time. Clients scan their stub to view real-time progress.

**Primary Functions:**
- Display current process status in workflow
- Show estimated wait time and queue position
- Track completed vs. remaining steps
- Read-only interface (no client actions)
- Support for multiple languages

### Visual Layout

```
+-----------------------------------------------------------------------+
|  FlexiQueue - Your Queue Status             [Program: Feeding 2025]  |
+-----------------------------------------------------------------------+
|                                                                       |
|  Status: IN PROGRESS                                                  |
|  Your Alias: Green Owl 42                                            |
|                                                                       |
|  +-----------+    +-----------+                                       |
|  | 7:00am    |    | 5:00pm    |                                       |
|  | Opening   |    | Closing   |                                       |
|  +-----------+    +-----------+                                       |
|                                                                       |
|  Standard Route Workflow:                                             |
|                                                                       |
|    +-------+       +----------+                                      |
|    | Step 1|       | Step 2   |                                      |
|    | Attd. | ----> | Submit   |                                      |
|    | Log   |       | Docs     |                                      |
|    +-------+       +----------+                                      |
|   ✓ DONE            ✓ DONE                                           |
|   (5 mins)          (8 mins)                                         |
|                          |                                           |
|                 (Current Step - GREEN)                              |
|                          v                                           |
|    +-------+       +----------+                                      |
|    | Step 4|  <--- | Step 3   |                                      |
|    | Claim |       | Interview|                                      |
|    | & Go  |       |          |                                      |
|    +-------+       +----------+                                      |
|   PENDING         [IN PROGRESS]                                      |
|                    (Started: 2 mins ago)                             |
|                                                                       |
|  +----------------------------------------------------+               |
|  | Please proceed now to Table 2 (Verification)     |               |
|  | You are 3rd in line at this table                |               |
|  | Average waiting time: 12 minutes                 |               |
|  +----------------------------------------------------+               |
|                                                                       |
|  [SCAN AGAIN]              [CANCEL]                                  |
|                                                                       |
|  *Auto-logout after 30 seconds of inactivity*                        |
|                                                                       |
+-----------------------------------------------------------------------+

ALTERNATE LAYOUT - UNREGISTERED STUB:
+-----------------------------------------------------------------------+

|  FlexiQueue - Queue Registration                                     |
|                                                                       |
|  Please scan your stub to register for the program:                  |
|  Feeding Program 2025                                                |
|                                                                       |
|  +------------------------------+                                    |
|  |                              |                                    |
|  |       [ SCAN QR CODE ]       |                                    |
|  |        [    ICON    ]         |                                    |
|  |                              |                                    |
|  +------------------------------+                                    |
|                                                                       |
|  [ Manual Input: ________________ ]                                   |
|                                                                       |
|  Available Languages:                                                |
|  [ English ] [ Filipino ] [ Cebuano ]                               |
|                                                                       |
+-----------------------------------------------------------------------+
```

### UI Components

| Element | Description |
|---------|-------------|
| **Header** | Program name, date/time, language selector |
| **Stub Alias** | Large, prominent display (e.g., "Green Owl 42") |
| **Opening/Closing Hours** | Program operating hours |
| **Workflow Diagram** | 4-step process visualization (boxes connected by arrows) |
| **Step Status** | Each step shows: ✓ DONE, [IN PROGRESS], or PENDING |
| **Time Tracking** | Duration spent in each completed step |
| **Current Action** | Highlighted green, shows current step and table location |
| **Wait Info** | Queue position, estimated wait time, current table assignment |
| **Action Buttons** | SCAN AGAIN (to refresh), CANCEL (exit) |
| **Auto-Logout** | System logs out after 30 seconds inactivity |

### Interaction Flow

1. Client arrives at QID (informant device)
2. **Unregistered stub:** Client scans or enters stub alias
   - System routes to appropriate program
   - Shows intro message with opening/closing hours
   - Offers language selection
3. **Registered stub:** Client scans and system displays:
   - Current status and workflow diagram
   - Completed steps (✓ DONE)
   - Current step (highlighted green)
   - Next steps (PENDING)
   - Time spent in current step
   - Queue position and estimated wait time
4. Client can:
   - Press "SCAN AGAIN" to refresh (update queue position)
   - Press "CANCEL" to exit and return to waiting area
5. System auto-logs out after 30 seconds of no activity
6. Display returns to "Scan your stub" welcome screen

---

## 4. Responsive Design Notes

### Mobile/Smartphone Variants

**QPD & QRD on Smartphone (5-6" screen):**
- Stack panels vertically instead of side-by-side
- Action buttons displayed as large, single-tap targets (44x44px minimum)
- Scanning area takes full width
- Details panel scrolls below main stats
- Performance chart simplified or hidden on small screens

**QID on Tablet/Kiosk (10-15" screen):**
- Workflow diagram scales larger for visibility from 3-5 meters away
- Text size: 24px+ for readability without proximity
- Touch targets: 60x60px minimum for clients
- No interaction required (read-only, scan-only interface)

---

## 5. Color & Accessibility

### Color Scheme

| State | Color | Hex | Purpose |
|-------|-------|-----|---------|
| Current/Active | Green | #22C55E | Highlight current step/action |
| Completed | Gray-Green | #10B981 | Shows finished steps |
| Waiting | Gray | #6B7280 | Shows pending steps |
| Alert | Red | #EF4444 | Shows errors or urgent actions |
| Info | Blue | #3B82F6 | Shows informational messages |
| Neutral | Gray | #9CA3AF | Shows neutral stats |

### Accessibility Features

- **High Contrast Mode:** Switch to high-contrast palette (dark background, light text)
- **Large Text:** Option to increase base font from 14px to 18px+ (especially for QID)
- **Audio Announcements:** QPD can trigger audio: "Now serving [alias] at Table [number]"
- **Screen Reader Compatible:** All buttons and fields labeled with aria-labels
- **No Color-Only Indicators:** Status always accompanied by text or icons

---

## 6. Device-Specific Layouts

### QPD (Queue Processing Device) - Smartphone
- Camera feed takes 60% of screen for scanning
- Action buttons arranged in 2x2 grid below camera
- Queue preview scrolls horizontally (swipe to see more)
- Performance chart simplified to single % number

### QRD (Queue Routing Device) - Smartphone or Touchscreen
- Priority buttons arranged in 2x2 grid (large, easy to tap)
- Route selection prominent (Standard vs. Custom)
- Confirmation panel pops up after selection
- Back button to re-scan if needed

### QID (Queue Informant Device) - Tablet or Kiosk
- Workflow diagram centered and oversized (visible from distance)
- Step boxes 100x80px minimum
- Text: 20px+ for main info, 16px+ for secondary info
- Buttons: 70x50px minimum
- Auto-rotate to landscape for wider workflow display

---

## 7. State Transitions & Edge Cases

### QPD States
1. **Idle:** Scanning prompt displayed
2. **Scanning:** Camera active, waiting for QR code
3. **Client Loaded:** Current client displayed with options
4. **Action Pending:** One of the 4 action buttons selected
5. **Confirmation:** User confirms action before executing
6. **Complete:** Action executed, next client loaded or prompt to scan

### QRD States
1. **Idle:** Scanning prompt displayed
2. **Scanning:** Camera active
3. **Stub Found:** Existing stub details shown OR new stub registration form
4. **Priority Selection:** User selects priority level
5. **Route Selection:** User selects routing path
6. **Confirmation:** Shows assignment details, ready to confirm
7. **Registered:** Stub sent to assigned table, ready for next client

### QID States
1. **Idle/Welcome:** "Scan your stub" prompt
2. **Scanning:** Camera active
3. **Unregistered:** Show registration message, wait for intake
4. **Registered:** Show workflow and status (normal operation)
5. **Auto-Logout:** Return to idle after 30 seconds inactivity

---

## 8. Future Enhancements

- **Real-time notifications:** QPD receives notification when stub approaching their table
- **Mobile queue position:** QID shows live queue position updates via WebSocket
- **Multi-language support:** All interfaces support Filipino, Cebuano, Ilocano
- **Accessibility mode:** Voice-guided navigation for visually impaired
- **Dark mode:** Toggle dark/light theme for comfort in different lighting
- **Performance analytics:** QID can show office-wide processing times (not just personal)
