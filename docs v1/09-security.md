## Security and Access Control

**Purpose:** Summarize the security model for FlexiQueue—threat assumptions, roles and permissions, data protection, and audit integrity.

Related docs: `03-domain-model.md`, `04-database-schema.md`, `08-edge-cases.md`

---

### 1. Threat Model

From architecture Section 11.1:

- **Scope**
  - System operates on a **local network only** (no direct internet exposure).
  - Primary threats are **insider misuse**, device theft, and misconfiguration—not internet attackers.

- **Considered Threats**
  - Unauthorized device access to the Wi‑Fi network.
  - Staff abusing privileges (e.g., skipping steps, overriding flows).
  - Physical theft or loss of server hardware.
  - Data tampering (attempts to alter logs or session history).

- **Out of Scope**
  - External internet attacks and cloud‑scale threat models—system is offline‑first and not exposed publicly.

Implication: security design focuses on **strong auditability and local access control** rather than perimeter firewalls or complex zero‑trust setups.

---

### 2. Role‑Based Access Control (RBAC)

From architecture Section 11.2:

#### 2.1 Roles

- **Admin**
  - Configure programs, tracks, and stations.
  - Register and assign hardware devices.
  - View all audit logs and reports.
  - Override any action in the system.

- **Supervisor**
  - Approve overrides initiated at stations.
  - Reassign staff between stations.
  - View station‑level logs.

- **Staff**
  - Operate assigned stations only.
  - Perform standard queue actions (call, serve, transfer, complete, mark no‑show).
  - Cannot modify or delete logs.

- **Public / Informant**
  - Anonymous users on informant displays.
  - Can only perform read‑only status checks.

#### 2.2 Enforcement Points

- HTTP middleware or policies should enforce:
  - `Admin` only for configuration endpoints (`/api/programs`, `/api/service-tracks`, `/api/track-steps`, `/api/hardware-units`, `/api/reports/audit`).
  - `Staff` or `Supervisor` for session operations at stations (`/api/sessions/*`, `/api/stations/*`).
  - Additional supervisor PIN or 2nd factor for critical flows (e.g., overrides, force completion, identity mismatch resolutions).
- WebSocket channels (e.g., `station.{id}`, `device.{mac}`) must:
  - Validate that the authenticated user or device is allowed to subscribe.
  - Enforce role checks and station assignment.

---

### 3. Data Protection

From architecture Section 11.3:

#### 3.1 Encryption

- **In Transit**
  - Use HTTPS where feasible (self‑signed cert is acceptable on local LAN).
  - WebSocket connections should be upgraded over TLS when HTTPS is used.
- **At Rest**
  - Database is **not encrypted at rest** by default, given offline, controlled deployments.
  - Rely on physical security and backups stored in controlled locations.

#### 3.2 Privacy

- Minimize stored personally identifiable information (PII):
  - Sessions are identified by **alias** (e.g., “A1”), not by names.
  - No personal names or IDs are stored in queue/session tables by default.
  - Any optional PII should be stored separately and only when necessary, with clear justification.
- This design supports compliance with the Philippine Data Privacy Act by reducing the sensitivity of the operational queue data.

---

### 4. Audit Log Integrity

The `transaction_logs` table is the core of COA compliance.

Key properties:

- **Append‑only**
  - New entries are inserted for every significant action.
  - No UPDATE or DELETE operations should be allowed through application code.
- **Chainable Integrity (optional enhancement)**
  - Each log may store a hash of the previous log entry:
    - e.g., `prev_hash` column containing a hash of the previous row’s contents.
  - Allows offline verification of tampering attempts.
- **Periodic Verification**
  - A maintenance command can recompute checksums over `transaction_logs`.
  - Discrepancies should be flagged and investigated.

Every critical business rule (e.g., overrides, forced completions, no‑shows, sequence violations) must leave a clear, human‑readable trace in `transaction_logs`.

---

### 5. Device and Network Security

- **Wi‑Fi**
  - Use WPA2‑PSK with a strong passphrase.
  - Rotate passphrases periodically and when staff change.
  - Prefer using a dedicated router/AP rather than a personal hotspot when possible.

- **Hardware Units**
  - Each `HardwareUnit` is registered with a unique `mac_address`.
  - Backend validates MAC for all hardware‑initiated actions.
  - Misbehaving devices can be flagged (`status = 'disabled'`) and are blocked at the API level (see `08-edge-cases.md`).

- **Server**
  - Restrict physical access to the server laptop/PC.
  - Protect OS user accounts with strong passwords.
  - Avoid running non‑essential services on the same machine.

---

### 6. Misuse and Fraud Scenarios

Security is partly implemented as **process control** and UX:

- **Process Skipper**
  - Invalid out‑of‑order scans are blocked with red screens and must go through a supervisor override flow.
- **Token Swap Fraud**
  - Station UI explicitly prompts staff to verify ID for priority categories and record mismatches with remarks.
- **Ghost Client and Double Scan**
  - Clear no‑show logic and double‑scan warnings reduce opportunities for silent manipulation.

All of these behaviors are tied back into audit logs so anomalies can be traced to specific users and decisions.

---

### 7. Developer Checklist

When implementing features, verify:

- Does this action require a specific role (admin/supervisor/staff)?
- Is every important state change recorded in `transaction_logs`?
- Could this endpoint be abused to bypass required steps or inflate/deflate queue statistics?
- Are we leaking any PII unnecessarily in responses or logs?
- Are WebSocket subscriptions limited to the correct station/device?

If the answer to any of these is unclear, adjust design or consult this doc and `03-domain-model.md` before proceeding.

