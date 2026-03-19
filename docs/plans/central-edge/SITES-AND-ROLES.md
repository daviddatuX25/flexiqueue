# Sites and roles — what they mean

This doc clarifies how **sites** and **admins** work in Central+Edge so you don’t hit “you must be assigned to a site” and so the model (site vs program vs Orange Pi) is clear.

---

## What is a “site”?

A **site** is the **tenant/organisation** that owns programs and users. It is **not** “only” the Orange Pi in the field.

| Concept | Meaning |
|--------|--------|
| **Site** | Establishment/tenant. Has: name, slug (e.g. `default`, `mswdo-dagupan`), API key for edge auth, `edge_settings` (sync options, scheduled sync time, etc.). Programs and users belong to a site (`site_id`). |
| **Program** | Always belongs to **one site** (`programs.site_id`). The queue configuration (stations, tracks, settings) is per program. |
| **Orange Pi (edge)** | A physical device. It is configured with **one** site: `SITE_ID` = that site’s slug and `CENTRAL_API_KEY` = that site’s API key. It receives **packages** for programs that belong to **that same site**. So: site = who owns the data; Pi = one deployment target for that site. |

So:

- **Yes, a program is required to be assigned to a site.** When you build a package for an edge “node”, you’re packaging a program that belongs to a site; the Pi is configured for that site and gets packages only for that site’s programs.
- **A site is like the establishment** (e.g. MSWDO Tagudin, MSWDO Dagupan). The “checklists” and settings for the package (what to sync, when, etc.) come from that site’s `edge_settings` and the program’s data.

---

## Why “you must be assigned to a site”?

In Phase B we **scope** admin actions by **the logged-in user’s site**: an admin may only list/create/edit programs and users for **their** `site_id`. If `user.site_id` is `null`, the app returns 403 for those actions so we don’t leak data across tenants.

So:

- **Every admin (and staff) must have a `site_id`** set. If your admin was created before sites existed, or by a seeder that didn’t set `site_id`, they have `site_id = null` and get “you must be assigned to a site”.

**Fix for existing data:** run the default-site seeder so all users and programs get assigned to the default site:

```bash
./vendor/bin/sail artisan db:seed --class=DefaultSiteSeeder
# or
php artisan db:seed --class=DefaultSiteSeeder
```

That updates all `users` and `programs` with `site_id = null` to the default site. After that, your current admins will have a site and admin functions work again.

**Fix for fresh seeds:** `DatabaseSeeder` runs `DefaultSiteSeeder` once at the start, then sets `site_id` to the default site when creating each user and each program. No second seeder call; no null `site_id` after `db:seed`.

---

## Do we need “site admin” vs “super admin”?

**Current behaviour:**

- There is **no** “super admin” role. Every admin is a **site-scoped admin**: they only see and manage programs/users for their own `user.site_id`.
- In a **single-tenant** setup you typically have **one** site (e.g. “Default Site”). All admins and programs get that `site_id` (via seeder or manual assign). So in practice everyone is a “site admin” for that one site and behaviour matches the old “one central server” model.

**If you want a “super admin”:**

- A **super admin** would be someone who can manage **all sites** (create sites, see all programs across sites, assign users to any site). The current spec doesn’t define this; we implemented strict per-site scoping only.
- To add it you could:
  - Introduce a role (e.g. `super_admin`) or a flag (e.g. `site_id = null` means “all sites” for that role), and
  - In scoping logic, allow those users to bypass the `site_id` filter (list all sites, all programs, all users).

That would be a small follow-up (e.g. “Super admin role / cross-site access”) and can be added when you need multi-tenant + platform admin.

---

## Who assigns a site to an admin? Is there a UI?

**Currently: it’s mostly implicit, and there is no UI to assign or change a user’s site.**

| When | What happens |
|------|-------------------------------|
| **New user created** | Backend sets `user.site_id = $request->user()->site_id` (the **creating admin’s** site). So the new user gets the same site as the admin who created them. No dropdown to pick a different site. |
| **Existing user** | There is **no** admin UI to “assign this user to site X” or to edit a user’s `site_id`. The only ways to set it today are: run `DefaultSiteSeeder` (assigns all nulls to the default site), or update the DB directly. |

So: **the “assign site to admin” flow is missing in the plan.** To support it properly you’d add e.g.:

- **User edit:** Show the user’s current site (read-only for site admins; editable only by a super admin), or  
- **Sites UI:** On a site’s show page, “Users in this site” with an action to move users between sites (again, super-admin only if you have multi-tenant).

Until then, new users inherit the creator’s site; existing users get a site only via seeder or DB.

---

## Multiple edge devices per site — do they get their own settings?

**No. In the current plan, settings are per site, not per device.**

- A **site** has one `edge_settings` (sync options, scheduled_sync_time, etc.).
- Every Pi that uses that site (same `SITE_ID` and same API key) is treated as the **same** site. So they all share the same `edge_settings`.
- If one site has three Orange Pis, all three use the same sync time, same sync_clients flag, etc. There is **no per-device** (per-Pi) settings in the spec.

If you need “Pi A at Branch 1 has different sync time than Pi B at Branch 2” while both belong to the same organisation, you’d either:

- Model each branch as a **separate site** (each with its own `edge_settings`), or  
- Extend the model later with something like “edge_device” or “node” with its own settings (not in the current plan).

---

## Is “site” only for the Pi? Is program–site only for packaging?

**In the current spec, no: site is used on central as well, not only for the Pi.**

The v2-final plan explicitly does two things:

1. **Central (multi-tenant):** “Programs from Site A are not visible in Site B’s program list.” So on central, programs and users are **scoped by site**. Admins only see their site’s data. So `program.site_id` and `user.site_id` are not “only for Pi” — they drive who sees what on central.
2. **Edge (Pi):** The Pi is configured with a site’s slug and API key; packages are built for programs that belong to that site. So the **same** site concept is used both for central tenant boundaries and for “which programs this Pi can run.”

So we did **not** design it as “site and edge are one” in the sense “site exists only on the Pi.” We designed **site as the tenant on central**, and the Pi then identifies as that tenant when it connects or receives a package.

If your intended model is different — e.g. **one central server, no multi-tenant on central; site only matters when packaging for a Pi** — that would be a design change:

- **Alternative:** Central shows all programs to all admins; `program.site_id` is only used when “export package for site X” (i.e. for which Pi). No scoping of admin by site on central. Then “assign site to admin” could be dropped or mean “this admin can export packages for these sites” only.

That alternative is **not** what the current spec implements; the spec uses site as the tenant boundary on central. If you want to move to “site only for Pi,” we’d treat it as a spec/design change and adjust scoping and UI accordingly.

---

## Summary

| Question | Answer |
|----------|--------|
| What is a site? | Tenant/organisation; owns programs and users; has API key and edge_settings for packaging/sync. |
| Is a site “only” the Orange Pi? | No. Site = establishment. Pi = edge device configured for one site and receives packages for that site’s programs. |
| Is a program required to be assigned to a site? | Yes. Every program has `site_id`. Packages for a Pi are built from programs of the site that Pi belongs to. |
| Why “you must be assigned to a site”? | Your admin user has `site_id = null`. Run `DefaultSiteSeeder` to assign them (and all programs) to the default site. |
| Site admin vs super admin? | See **[SUPER-ADMIN-VS-ADMIN-SPEC.md](SUPER-ADMIN-VS-ADMIN-SPEC.md)** for the full responsibility matrix. Super admin: sites, admins only, integrations, admin-level audit. Site admin: programs, tokens, staff, full audit, analytics, storage. Create super_admin via DB/seeder. |
| **Who assigns a site to an admin?** | **Done.** Staff page shows site; super admin can set/change site on create and edit. Site show lists "Users in this site" with "Move to site" for super admin. |
| **Multiple Pis per site — own settings?** | **No.** One site = one `edge_settings`. All devices for that site share the same settings. |
| **Site only for Pi / program–site only for packaging?** | **No in current spec.** Site is the tenant on central (programs/users scoped by site). If you want “site only for Pi,” that’s a design change. |

---

## How to get a super admin account

There is **no super_admin user by default**. To get one:

1. **Run the seeder** (creates or updates one user):
   ```bash
   ./vendor/bin/sail artisan db:seed --class=SuperAdminSeeder
   ```
   Uses `SUPER_ADMIN_EMAIL` (default `superadmin@flexiqueue.local`) and `SUPER_ADMIN_PASSWORD` (default `password`). Set these in `.env` if you want different credentials.

2. **Promote an existing user in the database:**
   ```sql
   UPDATE users SET role = 'super_admin', site_id = NULL WHERE email = 'your@email.com';
   ```

There is no in-app "promote to super admin" flow (by design).
