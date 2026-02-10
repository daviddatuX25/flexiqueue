# FlexiQueue Database Schema - Deep Dive Analysis

**Date:** January 29, 2026  
**Purpose:** Detailed justification for schema design decisions  
**Version:** 1.0

---

## Issue 1: PROGRAM_PROCESS - Why Remove sequence_order?

### The Problem

The original design included a `sequence_order` field in `PROGRAM_PROCESS` to represent process ordering. However, this assumes a **strict linear workflow**, which doesn't match real-world government service flows.

### Real-World Scenarios That Break sequence_order

#### Scenario 1: Branching Workflow
```
IF (Application Outcome == APPROVED):
  Process A → Process B → APPROVED_LETTER
ELSE IF (Application Outcome == REJECTED):
  Process A → Process B → REJECTION_LETTER
ELSE:
  Process A → Process B → RESUBMIT
```

**Problem:** What sequence_order do APPROVED_LETTER, REJECTION_LETTER, RESUBMIT have? They're all "Process 3" but can't coexist.

#### Scenario 2: Parallel Processing
```
Form Submission (Process A)
  ├→ Verification at Table 1 (Process B) [WAITING]
  ├→ Interview at Table 2 (Process C)     [WAITING simultaneously]
  └→ Background Check at Table 3 (Process D) [WAITING simultaneously]
```

**Problem:** B, C, D all have the same sequence_order? Or different? Both break linear assumption.

#### Scenario 3: Optional/Conditional Processes
```
Process A (Always required)
  ├→ Process B (If income < threshold) — sequence_order = 2?
  ├→ Process C (If special case) — sequence_order = 2?
  └→ Process D (Always) — sequence_order = 3?
```

**Problem:** Multiple processes can occur at same "level" depending on conditions.

#### Scenario 4: Loops/Rework
```
Process A: Submission
→ Process B: Initial Review
  ├→ APPROVED → Process C: Releasing
  └→ REJECTED → Process B again (Loop!)
```

**Problem:** sequence_order can't represent loops or rework cycles.

### The Solution: FLOW_RULE as Source of Truth

The schema already has **FLOW_RULE** table that explicitly defines workflow:

```sql
FLOW_RULE {
    flow_rule_id PK
    program_id FK
    from_process_id FK → PROCESS_TYPE
    to_process_id FK → PROCESS_TYPE
    condition_type VARCHAR(50)  -- AUTO, MANUAL, CONDITIONAL
    can_override BOOLEAN
}
```

**This is the actual workflow definition.**

### Revised PROGRAM_PROCESS Table

```sql
CREATE TABLE program_process (
    program_process_id SERIAL PRIMARY KEY,
    program_id INTEGER FK,
    process_type_id INTEGER FK,
    required BOOLEAN DEFAULT TRUE,          -- Is this process mandatory?
    is_initial BOOLEAN DEFAULT FALSE,       -- Can this process start a workflow?
    created_at TIMESTAMP,
    UNIQUE(program_id, process_type_id)
);
```

**Changes:**
- ❌ REMOVED: `sequence_order` (workflow defined by FLOW_RULE, not by order)
- ✅ ADDED: `is_initial` (marks which processes can START the workflow)

### How FLOW_RULE Replaces sequence_order

```sql
-- Find initial processes (how to start workflow)
SELECT pt.process_type_id, pt.name
FROM PROGRAM_PROCESS pp
JOIN PROCESS_TYPE pt ON pp.process_type_id = pt.process_type_id
WHERE pp.program_id = $1 AND pp.is_initial = TRUE;

-- Find next process(es) after completion
SELECT pt.*, fr.condition_type, fr.can_override
FROM FLOW_RULE fr
JOIN PROCESS_TYPE pt ON fr.to_process_id = pt.process_type_id
WHERE fr.program_id = $1 
  AND fr.from_process_id = $2
  AND fr.condition_type = 'AUTO';

-- Support for multiple next processes (user chooses)
SELECT pt.*, fr.condition_type
FROM FLOW_RULE fr
JOIN PROCESS_TYPE pt ON fr.to_process_id = pt.process_type_id
WHERE fr.program_id = $1 
  AND fr.from_process_id = $2
  AND fr.condition_type = 'MANUAL';

-- Check if process can rework/loop back
SELECT pt.*, fr.condition_type
FROM FLOW_RULE fr
JOIN PROCESS_TYPE pt ON fr.from_process_id = pt.process_type_id
WHERE fr.to_process_id = $current_process_id
  AND fr.program_id = $1;
```

### Admin UX for Building Workflows

Instead of ordering processes by number (1, 2, 3...), admin:

1. **Check "Initial" for starting processes**
2. **Draw arrows (FLOW_RULE) between processes** to define workflow
3. **Set condition type** on each arrow (AUTO/MANUAL/CONDITIONAL)
4. **Set can_override** flag on each arrow

Example UI mockup:
```
[Form Submission] ← check as "Initial"
       ↓ (AUTO)
[Verification] 
    ↙ (MANUAL)      ↘ (MANUAL)
[APPROVED]         [REJECTED]
```

---

## Issue 2: DEVICE_ASSIGNMENT and Staff - Hybrid Approach

### The Problem

Question: Should device assignments include staff_id (pre-assign devices to staff)?

**Original Design:**
```sql
DEVICE_ASSIGNMENT {
    device_id, program_id, table_id, assignment_role
    -- NO staff_id
}
```

This allows ANY staff to use ANY assigned device, which is flexible but requires staff to select device/program at login.

### Two Approaches Analyzed

#### Approach A: Device → Role Only (No staff_id)
```
Admin assigns:
  Device-001 → AICS Program → Table 2 → QPD role

Staff login workflow:
  1. Staff opens app
  2. Sees available devices (Desktop, Tablet)
  3. Selects "Device-001" or scans QR
  4. System shows: "AICS Program, Table 2"
  5. Staff logs in
  6. Creates DEVICE_SESSION
  
Pros: Flexible, supports shift changes, any staff can use
Cons: Staff must know which device, more clicks
```

#### Approach B: Device → Staff → Program → Table (With staff_id)
```
Admin assigns:
  Device-001 → AICS Program → Table 2 → Jane

Staff login workflow:
  1. Jane opens app on Device-001
  2. System checks: "Is Jane assigned to Device-001?"
  3. Auto-populates: AICS Program, Table 2
  4. Jane just confirms & logs in
  5. Creates DEVICE_SESSION
  
Pros: Simpler login, admin controls who uses what, clear device ownership
Cons: Less flexible, must reassign for shift changes
```

### Recommended Solution: HYBRID

**Keep both models working together:**

```sql
-- Level 1: Device → Program/Table/Role (what does device do?)
DEVICE_ASSIGNMENT {
    assignment_id PK
    device_id FK
    program_id FK
    table_id FK (nullable for QRD/QID)
    assignment_role VARCHAR(50)  -- QPD, QRD, QID, IOT_DISPLAY
    active BOOLEAN
    assigned_at TIMESTAMP
    unassigned_at TIMESTAMP
    assigned_by_staff_id FK
    UNIQUE(device_id, program_id)
}

-- Level 2: Device → Staff (optional: who should use this device?)
STAFF_DEVICE_ASSIGNMENT {
    staff_device_assignment_id PK
    device_id FK
    staff_id FK
    assigned_from TIMESTAMP
    assigned_until TIMESTAMP (NULL = until manually unassigned)
    notes VARCHAR(200)  -- "Weekday morning", "Jane's table", etc
    created_at TIMESTAMP
}

-- Level 3: Device → Session (who actually logged in?)
DEVICE_SESSION {
    session_id PK
    device_id FK
    staff_id FK
    session_start TIMESTAMP
    session_end TIMESTAMP
    session_token VARCHAR(255)
    last_activity TIMESTAMP
}
```

### How the Hybrid Model Works

**Scenario 1: Flexible shift system (no pre-assignment)**

```
Admin setup:
  DEVICE_ASSIGNMENT: Device-001 → AICS → Table 2 → QPD
  STAFF_DEVICE_ASSIGNMENT: (empty, no pre-assignment)

Morning (8 AM):
  Jane logs in to any available device
  System: "Which device? Which program?"
  Jane: Selects Device-001, confirms AICS
  DEVICE_SESSION created: (Device-001, Jane, 8:00 AM)

Afternoon (1 PM):
  John logs in to same Device-001
  DEVICE_SESSION created: (Device-001, John, 1:00 PM)
  Previous session ends
```

**Scenario 2: Dedicated device setup (with pre-assignment)**

```
Admin setup:
  DEVICE_ASSIGNMENT: Device-001 → AICS → Table 2 → QPD
  STAFF_DEVICE_ASSIGNMENT: Device-001 → Jane (assigned_from: now, assigned_until: NULL)

Morning (8 AM):
  Jane opens app
  System detects: "Jane is assigned to Device-001"
  Auto-populates: AICS Program, Table 2
  Jane just confirms login
  DEVICE_SESSION created: (Device-001, Jane, 8:00 AM)
  
Shift change (12 PM):
  Admin removes: STAFF_DEVICE_ASSIGNMENT (Jane)
  Admin adds: STAFF_DEVICE_ASSIGNMENT (John, 12:00 PM - 5:00 PM)
  
Afternoon (1 PM):
  John opens app
  System: "John is assigned to Device-001"
  Auto-populates: AICS Program, Table 2
  John logs in
  DEVICE_SESSION: (Device-001, John, 1:00 PM)
```

**Scenario 3: Override flexibility**

```
Admin setup:
  DEVICE_ASSIGNMENT: Device-001 → AICS → Table 2 → QPD
  STAFF_DEVICE_ASSIGNMENT: Device-001 → Jane

Jane logs in:
  System auto-selects AICS/Table 2
  Jane: "Actually, I need to help with 4Ps intake today"
  Jane taps: "Switch Program"
  System checks: DEVICE_ASSIGNMENT for Device-001 with program=4Ps
  If found: Allows switch with reason logging
  If NOT found: "Device not assigned to 4Ps. Contact admin."
```

### When to Use Each Level

| Scenario | DEVICE_ASSIGNMENT | STAFF_DEVICE_ASSIGNMENT | Use Case |
|----------|------------------|------------------------|----------|
| Flexible shifts | Yes | ← Empty → | Any staff can use any device (small office) |
| Dedicated devices | Yes | Yes (current staff only) | Each device has owner (large office) |
| Multiple programs | Yes | Yes (date-range) | Device rotation between programs |
| Intake & Processing | Yes | No | Multiple roles use same device type |
| IoT Displays | Yes | No | Device paired to location, not person |

### SQL Operations

**Query 1: What device should Jane use?**
```sql
SELECT d.*, sda.notes, da.program_id, da.table_id
FROM STAFF_DEVICE_ASSIGNMENT sda
JOIN DEVICE d ON sda.device_id = d.device_id
JOIN DEVICE_ASSIGNMENT da ON d.device_id = da.device_id
WHERE sda.staff_id = ? 
  AND sda.assigned_from <= NOW() 
  AND (sda.assigned_until IS NULL OR sda.assigned_until > NOW())
  AND da.active = TRUE;

-- Result: "Device-001 (iPad), AICS Program, Table 2"
```

**Query 2: List all devices available for assignment**
```sql
SELECT d.*
FROM DEVICE d
WHERE d.active = TRUE
  AND NOT EXISTS (
    SELECT 1 FROM STAFF_DEVICE_ASSIGNMENT sda 
    WHERE sda.device_id = d.device_id 
      AND sda.assigned_from <= NOW() 
      AND (sda.assigned_until IS NULL OR sda.assigned_until > NOW())
  );

-- Returns: Unassigned devices only
```

**Query 3: Shift change - who's using Device-001 now?**
```sql
SELECT ds.staff_id, s.staff_name, ds.session_start
FROM DEVICE_SESSION ds
JOIN STAFF s ON ds.staff_id = s.staff_id
WHERE ds.device_id = ? 
  AND ds.session_end IS NULL
  AND EXTRACT(DATE FROM ds.session_start) = CURRENT_DATE;

-- Returns: Current user on this device
```

---

## Impact on Implementation

### Backend Changes

**Laravel Example:**
```php
// Before login, check if staff has assigned device
$assignedDevice = StaffDeviceAssignment::where('staff_id', auth()->id())
    ->whereBetween('assigned_from', [now(), now()])
    ->with('device.assignment')
    ->first();

if ($assignedDevice) {
    // Pre-populate form
    return [
        'device_uuid' => $assignedDevice->device->device_uuid,
        'program_id' => $assignedDevice->device->assignment->program_id,
        'table_id' => $assignedDevice->device->assignment->table_id,
    ];
} else {
    // Show device/program selector
    return $this->getAvailableDevices();
}
```

### Database Indices (Important for Performance)

```sql
-- PROGRAM_PROCESS: Find initial processes quickly
CREATE INDEX idx_program_process_initial 
  ON program_process(program_id, is_initial);

-- FLOW_RULE: Find next processes
CREATE INDEX idx_flow_rule_from 
  ON flow_rule(program_id, from_process_id);

-- STAFF_DEVICE_ASSIGNMENT: Find assigned devices
CREATE INDEX idx_staff_device_assignment_staff 
  ON staff_device_assignment(staff_id, assigned_from, assigned_until);

-- STAFF_DEVICE_ASSIGNMENT: Find assignment for device
CREATE INDEX idx_staff_device_assignment_device 
  ON staff_device_assignment(device_id, assigned_from, assigned_until);

-- DEVICE_ASSIGNMENT: Find active assignments
CREATE INDEX idx_device_assignment_active 
  ON device_assignment(device_id, program_id, active);
```

---

## Migration Path

If updating existing system:

```sql
-- Step 1: Add new columns
ALTER TABLE program_process 
  ADD COLUMN is_initial BOOLEAN DEFAULT FALSE;

ALTER TABLE staff_device_assignment 
  CREATE TABLE IF NOT EXISTS (
    ... as defined above
  );

-- Step 2: Mark initial processes (those without incoming FLOW_RULEs)
UPDATE program_process SET is_initial = TRUE
WHERE program_process_id NOT IN (
  SELECT DISTINCT from_process_id 
  FROM flow_rule 
  WHERE program_id = program_process.program_id
);

-- Step 3: Deprecate sequence_order
-- Option A: Keep for backward compatibility, ignore in queries
-- Option B: Drop after data migration period

ALTER TABLE program_process DROP COLUMN sequence_order;
```

---

## Summary

| Issue | Old Design | New Design | Benefit |
|-------|-----------|-----------|---------|
| **Workflow definition** | Linear sequence_order | FLOW_RULE graph | Supports branching, parallel, loops |
| **Process initiation** | Sort by sequence_order | Mark is_initial | Explicit, no sorting needed |
| **Device pre-assignment** | No staff_id in assignment | Optional STAFF_DEVICE_ASSIGNMENT | Flexible: can use both models |
| **Device assignment** | Device → Program only | Device → Program → Table | Clearer admin control |

This design supports **both operational styles**:
- **Flexible offices:** Use DEVICE_ASSIGNMENT only, staff selects device
- **Formal offices:** Use both levels, admin pre-assigns devices to staff

And supports **any workflow complexity:**
- Linear: Traditional > Form > Interview > Approval
- Branching: Approved/Rejected paths
- Parallel: Multiple tables simultaneously
- Looping: Rework scenarios
- Mixed: Any combination
