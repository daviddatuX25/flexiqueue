# PRD: Hybrid “Admin-first” authentication (username + Google; reset to Gmail)

| | |
|---|---|
| **Status** | **Canonical product spec** — implementation is phased; align code and [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md) with this document. |
| **Supersedes** | Prior drafts that described **Google-only** or **email-as-login** only. This PRD is the **single** identity/onboarding plan. |
| **Complements** | [`RBAC_AND_IDENTITY_END_STATE.md`](RBAC_AND_IDENTITY_END_STATE.md) (Spatie end state, no duplicate identity specs), [`docs/architecture/05-SECURITY-CONTROLS.md`](../architecture/05-SECURITY-CONTROLS.md) where applicable. |
| **Stack** | Laravel 12, Inertia + Svelte 5, session auth, **Laravel Socialite** (Google), **Symfony Mailer** → **SMTP** (see **Developer brief §2** — **Agila / HestiaCP mail server**). |

---

## 0. Developer brief (authoritative)

The following rules are **binding** for implementation and ops. If the sections below already restate them, **no duplicate work** — follow this brief when wiring mail and schema.

### 0.1 Authentication — hybrid and restricted

| Rule | Detail |
|------|--------|
| **No public registration** | Disable or remove any public sign-up routes. |
| **Admin-led onboarding** | All users are **created by an admin** first (**username + password** provisioned in DB). |
| **Provider linking** | Use a **`credentials`** (or `user_credentials`) table so **one `user_id`** links **multiple providers**: **local** (username + password hash) and **google** (OAuth subject / identifier). After first login with admin credentials, user may **Link Google Account** in profile settings. |
| **Google OAuth** | One-click convenience only; **must** match an email / identity **already** in the database — **no** auto-provisioned staff from unknown Google accounts. |

### 0.2 Outbound mail — SMTP (Agila / Hestia)

| Rule | Detail |
|------|--------|
| **No third-party transactional APIs** | Do **not** use **SendGrid**, **Mailgun**, or the **Google Gmail API** for **password resets** or **notifications** (per product ops). |
| **Mandatory Agila / Hestia SMTP** | Use **Agila Hosting / HestiaCP mail server** for **all** outbound transactional mail. |
| **Implementation** | Standard Laravel `MAIL_MAILER=smtp`: host (e.g. mail for your domain), **port 465 (SSL)** or **587 (TLS)** — match panel docs. |
| **DNS** | Configure **SPF**, **DKIM**, and **DMARC** so messages (including to **Gmail** inboxes) are not routinely classified as spam. Document in [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md). |

### 0.3 Account recovery

| Path | Detail |
|------|--------|
| **Primary** | **Forgot password** on the site → reset token email sent **via Hestia SMTP** to **Gmail on file** (`recovery_gmail` or equivalent). |
| **Fail-safe** | **Admin reset** in the dashboard: admin sets **temporary / new password** when the user is locked out of **both** local login and **Google** (and mail is down or Gmail missing). |

---

## 1. Executive summary

Deliver a **secure, admin-controlled** authentication module:

- **No public self-registration** — only admins create user records (or future invite flows that still end in admin approval).
- **Two login methods** for end users:
  1. **Local:** **username + password** — the **login identifier is `username`**, not email (see §4.1).
  2. **Google:** **Sign in with Google** after the user **links** Google (or admin records the same Gmail); OAuth matches existing users by **Google account email** / `google_id`.
- **Fallback:** If Google is unavailable, **username + password** and **Forgot password** apply.
- **Self-service password reset:** User triggers reset (**username**); app sends the reset link **to the Gmail on file** for that user, **via Agila/Hestia SMTP** (§0.2).
- **Outbound mail:** **Mandatory** Agila/Hestia SMTP — **no** SendGrid/Mailgun/Gmail API for transactional mail (§0.2). **SPF / DKIM / DMARC** on the sending domain.

This is **hybrid** by design: OAuth is optional convenience; **local username/password** plus **Gmail-delivered reset** are the contract for recovery.

---

## 2. Roles and responsibilities

| Role | Responsibilities |
|------|------------------|
| **Super admin** (`platform.manage` / product equivalent) | Create users with **username**, initial password, and **Gmail for recovery** (or ensure it exists before self-service reset); **set temporary password**; deactivate accounts; configure OAuth and mail env. |
| **Site admin** | Create users **for their site** (per FlexiQueue scoping); same fields as above where applicable. |
| **End user** | Log in via **username/password** or **Google** (if linked); **Forgot password** (link sent **to Gmail on file**); **Settings** — Link/Unlink Google. |

*Authorization remains **Spatie** + policies — this PRD defines **identity** and **session** behavior.*

---

## 3. Functional requirements

### 3.1 Restricted onboarding (admin only)

| ID | Requirement |
|----|----------------|
| **ONB-1** | **Manual entry:** Admin creates a user with at least **full name**, **unique `username`**, **initial password** (or generated temp password), **Gmail for recovery** (see below), **role** + Spatie sync ([`UserController`](../../app/Http/Controllers/Api/Admin/UserController.php)), and **site** where applicable. |
| **ONB-2** | **Gmail on file:** Each user record stores a **Gmail address** used exclusively (for this PRD) as the **destination for password-reset emails** — e.g. column `recovery_gmail` or `google_account_email`, **normalized** (store the address returned by Google on link, or admin-entered `@gmail.com` / Google Workspace Gmail). **Self-service forgot password requires this field** to be set; otherwise only **admin temporary password** works. |
| **ONB-3** | **No public signup:** No open `/register` for queue access; marketing routes redirect to “Contact your administrator.” |
| **ONB-4** | **First login:** User signs in with **username + password** (or temp password). |
| **ONB-5** | **Provisioning state (recommended):** Optional `pending_assignment` — align with [`RBAC_AND_IDENTITY_END_STATE.md`](RBAC_AND_IDENTITY_END_STATE.md). |

### 3.2 Hybrid authentication logic

| ID | Requirement |
|----|----------------|
| **AUTH-1** | **Primary login page:** **Username**, **Password** (replaces email-as-login everywhere in the auth UI). |
| **AUTH-2** | **Google:** **Sign in with Google** — Socialite; scopes **`openid`**, **`email`**, **`profile`** (minimal). |
| **AUTH-3** | **Account linking:** When Google returns an email that matches **`recovery_gmail` / linked Gmail** on an existing user, **attach** `google_id` and log in — **no** duplicate user. |
| **AUTH-4** | **No orphan Google users:** Unknown Google email → **no** auto-created staff; message: contact administrator. |
| **AUTH-5** | **Unlink:** User may unlink Google in Settings; **local username/password** still works; **forgot password** still sends to **Gmail on file** (must remain set). |

### 3.3 Password recovery (Gmail destination; Agila/Hestia SMTP)

| ID | Requirement |
|----|----------------|
| **PWD-1** | **Forgot password:** User submits **username**. System resolves the user and sends **one** email with the reset link **to that user’s Gmail on file** (`recovery_gmail` or equivalent). |
| **PWD-2** | **Mail stack (mandatory):** Laravel **`MAIL_MAILER=smtp`** to **Agila / HestiaCP** mail server — **do not** use SendGrid, Mailgun, or Gmail API for transactional resets/notifications (per §0.2). |
| **PWD-3** | **Hashing:** Passwords: **bcrypt** or **argon2** per `config/hashing.php` (stored via **local** credential row or denormalized for guard compatibility). |
| **PWD-4** | **Rate limiting:** Throttle forgot-password and reset endpoints (e.g. per IP + per username). |
| **PWD-5** | **Admin reset (fail-safe):** Dashboard action to **set/update password** when user is locked out of **both** local and Google, or when Gmail/SMTP is unavailable — same as **temporary password** / admin override. |
| **PWD-6** | **Deployment:** [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md): Hestia SMTP host/port/TLS, `MAIL_FROM`, **SPF + DKIM + DMARC**. |

### 3.4 Profile management

| ID | Requirement |
|----|----------------|
| **PROF-1** | **Link Google:** Settings — OAuth; persist `google_id` and **confirm/update Gmail on file** from Google `email` claim. |
| **PROF-2** | **Change Gmail (recovery):** Allowed only with verification **sent to the new Gmail** or via admin — **reset emails always use the current Gmail on file**. |
| **PROF-3** | **Username:** Change **username** only via admin or strict verified flow (product choice) — avoid lockouts. |

---

## 4. Technical specification (FlexiQueue-tailored)

### 4.1 Schema (target)

**`users`** — profile and tenancy (name, `site_id`, `recovery_gmail`, Spatie, etc.). **No** long-term reliance on `password` on `users` alone if **`credentials`** table is the source of truth (see below).

**`credentials` (or `user_credentials`) — required pattern (per developer brief)**

| Column (conceptual) | Purpose |
|---------------------|---------|
| `user_id` | FK to `users.id` |
| `type` / `provider` | e.g. `local` \| `google` |
| `identifier` | **Local:** unique **username**. **Google:** stable **`google_id`** (subject) and/or linked lookup by Gmail |
| `secret` | **Local:** Argon2/bcrypt hash. **Google:** null (OAuth does not store password here) |
| `timestamps` | Optional |

One **user** row → **one** `local` credential (username + hash) + optional **`google`** credential row after linking. Login resolution: find **local** credential by username; OAuth: match **Google** email/`google_id` to existing user, then attach **google** credential row.

| Field on `users` | Purpose |
|-------------------|---------|
| **`recovery_gmail`** (name flexible) | **Gmail** where **forgot-password** emails are sent; align with Google `email` after link. |
| **`email`** (optional) | Legacy / Laravel compatibility only if needed — **not** the local login id. |

**`password_reset_tokens`** — Laravel default; **notification must** address **`recovery_gmail`**. Implement **custom** `sendPasswordResetNotification` (or equivalent) so **Mail** does not use the wrong address.

**Denormalization:** You may keep `users.password` in sync with the **local** credential row **temporarily** for `EloquentUserProvider` — or implement a **custom user provider** reading from `credentials`; document the chosen approach in code comments.

### 4.2 Routes and controllers (target)

| Area | Implementation notes |
|------|------------------------|
| Login | Resolve **local** credential by **username** + verify hash (from `credentials` or synced column). |
| Forgot / reset | **Username** → user → reset mail **to `recovery_gmail`** via **Hestia SMTP**. |
| Google OAuth | `/auth/google`, `/auth/google/callback` — only if email/`google_id` matches an **existing** user + **credential** link. |
| Public routes | No public `/register` route (or redirect to contact admin). |

### 4.3 Security

| Topic | Rule |
|-------|------|
| **Passwords** | Hashed; rehash on login if needed. |
| **OAuth** | `state` validation; **HTTPS** in production. |
| **Reset tokens** | Single-use, expiry; do not leak whether username exists (**same response** for unknown username — optional hardening). |
| **Edge** | OAuth and mail are **central**; edge devices unchanged. |

### 4.4 Mail — Agila / Hestia SMTP

- **Mandatory:** outbound transactional mail (resets, notifications) via **Agila / HestiaCP** SMTP — **not** SendGrid, Mailgun, or Gmail API (§0.2).
- **Ports:** **465** (SSL) or **587** (STARTTLS/TLS) per server/panel configuration; set `MAIL_SCHEME` / encryption in `.env` accordingly.
- **DNS:** **SPF**, **DKIM**, **DMARC** for the sending domain to protect deliverability to **Gmail** and others.
- **Success metric:** reset message reaches the user’s **Gmail inbox** in **&lt; 5 minutes** under normal conditions.

---

## 5. User experience flows

1. Admin creates **John**, username `john.doe`, temp password, **recovery Gmail** `john@gmail.com`, site + role.
2. John logs in with **username + password**; may **Link Google** — Gmail on file matches Google.
3. Next time John may use **Sign in with Google**.
4. If John forgets password: **Forgot password** → enter **username** → **email with link** arrives at **john@gmail.com** (Gmail on file).
5. If John has no Gmail on file: admin uses **temporary password** or sets Gmail first.

---

## 6. Success metrics

| Metric | Target |
|--------|--------|
| **Unauthorized registrations** | **0** |
| **Forgot password** | Reset link delivered to **Gmail on file** via **Agila/Hestia SMTP** |
| **Recovery time** | **&lt; 5 minutes** median for self-service path when Gmail is set |

---

## 7. Implementation phases (engineering)

| Phase | Scope | Deliverables |
|-------|--------|--------------|
| **H1** | **Schema** | `credentials` table (local + google); `users.recovery_gmail`; migrate login from email; optional `users.password` sync |
| **H2** | **Google OAuth** | Socialite; link/update Gmail; no auto-create |
| **H3** | **Forgot/reset** | Username-based request; **notification to `recovery_gmail`**; rate limits; Inertia pages |
| **H4** | **Admin override** | Temporary password; audit |
| **H5** | **Onboarding gate** | Pending assignment middleware if used |
| **H6** | **Docs** | [`DEPLOYMENT.md`](../DEPLOYMENT.md): Agila/Hestia SMTP, **SPF/DKIM/DMARC**; no public `/register` |

**Tests:** PHPUnit — login by username; forgot password sends to `recovery_gmail`; Google callback match; throttle.

---

## 8. Out of scope (unless added later)

- **Microsoft / Apple** sign-in.
- **Public** open registration.
- **Sending reset links to non-Gmail addresses** (extend later if needed).

---

## 9. References

- [`routes/web.php`](../../routes/web.php) — `/login`, `/logout`
- [`docs/plans/RBAC_AND_IDENTITY_END_STATE.md`](RBAC_AND_IDENTITY_END_STATE.md)
- [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md)

---

## Document history

| Date | Change |
|------|--------|
| **2026-03-22** | Initial PRD: hybrid admin-first auth, local email/password + optional Google, Hestia SMTP; FlexiQueue-tailored schema. |
| **2026-03-22** | **Revision:** **username** for local login; **forgot password** sends reset link to **Gmail on file**; **outbound mail not** Hestia panel email feature — deployment-configured provider. |
| **2026-03-22** | **Developer brief:** **Agila/Hestia SMTP mandatory** (no SendGrid/Mailgun/Gmail API for transactional); **SPF/DKIM/DMARC**; **`credentials`** table for local + Google; **admin reset** fail-safe; §0 added. |
