# Token per-site or shared pool

**Context:** Tokens table has no `site_id`. Today the admin token list returns all tokens; so in multi-tenant, Site B can see and assign tokens that belong to Site A. See [../SITE-SEPARATION-STUDY.md](../SITE-SEPARATION-STUDY.md) § 3.3.

**Options:**

1. **Per-site token pool (schema):** Add `tokens.site_id`. Set on token create from creating user’s site (or program’s site). Scope token list, create, update, and bulk-assign so admins only see/manage tokens in their site. Assignment to program remains valid only when program and token share the same site.
2. **Shared pool (query-only):** Keep tokens global. Scope **list** by “tokens that are in at least one program in my site” plus optionally “tokens not in any program” (unassigned pool). Bulk assign already receives program id (in my site); restrict assignable tokens to those in same site’s programs or allow any. Document that physical token pool is shared across sites.
3. **Seeder-only (testing):** For manual testing (e.g. LGU mirror), seed a separate set of token rows for the second site and assign only those to that site’s programs. Does not fix the global token list API; use only to simulate “separate pools” in tests.

**Product decision needed:** Do we want each site to have its own token pool (no sharing), or one shared pool with visibility/assignment scoped by site? Then implement Option 1 or 2 and add a bead to central-edge-tasks or Phase B/C follow-up if desired.
