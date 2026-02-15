# QR Token Print System — Plan for Beads

**Purpose:** Enable bulk printing of physical token cards with QR codes for client distribution. This plan defines scope, templates, edge cases, and bead breakdown for implementation.

---

## 1. Scope

### 1.1 In Scope
- **Bulk print** — Admin selects a set of tokens (by status, prefix, or program) and generates a printable page/sheet.
- **Token card template** — Print layout: physical ID (e.g. "A1"), QR code (encoding `qr_code_hash` or a URL like `/display/status/{qr_hash}`), optional program name, branding.
- **Print-ready output** — HTML/CSS or PDF optimized for common paper sizes (A4, letter, label sheets).

### 1.2 Out of Scope (Phase 1)
- Thermal printer integration.
- Custom QR content (e.g. short URLs, different encoding).
- Per-token design customization (all tokens use same template).

---

## 2. QR Code Content

**Recommended content:** The value clients scan must resolve to the status check. Options:
- **Option A:** Full URL, e.g. `https://{host}/display/status/{qr_hash}` — works when host is known.
- **Option B:** Relative path `/display/status/{qr_hash}` — clients must be on same network; scanner/browser resolves relative to current host.
- **Option C:** QR encodes only `qr_hash`; client app or kiosk appends base URL.

**Phase 1 choice:** Use `{base_url}/display/status/{qr_hash}` where `base_url` is configurable (e.g. `APP_URL`). For offline/local, `http://192.168.1.100/display/status/abc123...`.

---

## 3. Print Template Design

### 3.1 Card layout (per token)
- **Physical ID** — Large, readable (e.g. "A1", "B15").
- **QR code** — Sufficient size for reliable scan (min ~2cm × 2cm).
- **Optional:** Program name, "Scan for status" hint, small logo.

### 3.2 Sheet layout
- **Grid** — Multiple cards per page (e.g. 4–8 per A4).
- **Cut lines** — Dashed borders for cutting if using perforated or hand-cut cards.
- **Page break** — Avoid splitting a card across pages.

### 3.3 Paper / label considerations
- Support A4 and US Letter.
- Optional: Avery/label sheet presets (e.g. 2×5, 4×6) if common in MSWDO context.

---

## 4. Edge Cases & Handling

### 4.1 Token selection
| Edge case | Handling |
|-----------|----------|
| No tokens selected | Disable Print button; show "Select at least one token." |
| Tokens in `in_use` | Allow print (card may be replaced); warn "X tokens are currently in use. Print replacement cards?" |
| Tokens `lost` or `damaged` | Include in selection; warn "X tokens are lost/damaged. Printing replacements." |
| 1000+ tokens | Paginate or chunk; stream PDF in parts; show progress; cap at configurable limit (e.g. 500 per request) with "Print in batches" message. |
| Deleted token after selection | Validate at print time; skip missing, log, continue; report "Y tokens skipped (no longer exist)." |
| Selection includes tokens from different programs | If program-scoped: filter by active/default program. Else: allow; card shows program name if multi-program. |
| Filter returns zero tokens | Show "No tokens match filters." Disable Print. |
| Concurrent print requests (same user) | Allow; each request gets own PDF. No locking. |

### 4.2 QR generation
| Edge case | Handling |
|-----------|----------|
| `qr_code_hash` empty/null | Token invalid; exclude from print; log error; count in skip report. |
| Very long hash | QR supports 64-char hex; no truncation. |
| Base URL not configured | Fallback: `config('app.url')` or `window.location.origin`; warn in logs. |
| QR library fails | Catch exception; exclude token; add to skip report; continue. |
| Invalid UTF-8 or special chars in URL | Sanitize; QR encodes URL only. |

### 4.3 Print output
| Edge case | Handling |
|-----------|----------|
| Browser blocks print | Show "Allow pop-ups for this site" or use same-tab print with print CSS. |
| PDF generation fails | Fallback to HTML print view; "PDF failed. Use browser Print (Ctrl+P)." |
| Empty page (all tokens skipped) | Show "No tokens to print." No blank PDF. |
| Mixed orientations | Default portrait; option for landscape (more cards per page). |
| Print cancelled by user | No action; close preview. |
| Very small screen (mobile) | Preview may be cramped; recommend desktop for bulk print. |

### 4.4 Physical / operational
| Edge case | Handling |
|-----------|----------|
| Duplicate physical_id | Tokens have unique `qr_code_hash`; card shows physical_id + optional program. |
| Token created mid-session | New tokens in list; bulk print includes latest. |
| Printer out of labels | Admin responsibility; out of scope. |
| Card cut incorrectly | Provide clear cut lines; document in print instructions. |
| QR unreadable (too small) | Enforce min QR size (~2cm); test scan before bulk print. |
| Paper size mismatch | Template defaults A4/Letter; allow selection; warn if mismatch. |

---

## 5. API & Routes

### 5.1 API
- `GET /api/admin/tokens/print` — Query: `?ids=1,2,3` or `?status=available&prefix=A&limit=50`. Returns print payload (tokens with `physical_id`, `qr_code_hash`, `base_url`) or triggers PDF download.
- Alternative: `POST /api/admin/tokens/print-preview` — body: `{ token_ids: [1,2,3] }` — returns HTML/JSON for client-side render.

### 5.2 Page
- **Option A:** Modal or slide-over on Tokens Index with "Print selected" — uses selected token IDs.
- **Option B:** Dedicated `GET /admin/tokens/print` page with filters (status, prefix, program) and Preview/Print buttons.

---

## 6. Implementation Approach

### 6.1 QR code generation
- **Server:** PHP library (e.g. `chillerlan/php-qrcode`, `endroid/qr-code`) to generate PNG/SVG.
- **Client:** JS library (e.g. `qrcode`, `qr-code-styling`) to generate in browser.
- Recommendation: Generate on server for consistent output and PDF; cache small images if many tokens.

### 6.2 PDF generation
- Laravel: `barryvdh/laravel-dompdf` or `spatie/laravel-pdf` for HTML→PDF.
- Template: Svelte page (`Admin/Tokens/Print.svelte`) with token cards; receives cards from controller, inline QR data URIs.

### 6.3 HTML print fallback
- Svelte print page; `@media print` CSS for print styling.
- User uses Ctrl+P / Cmd+P for browser print.

---

## 7. Bead Breakdown

| Bead ID | Title | Description |
|---------|-------|-------------|
| QR-1 | Token print template (Svelte + CSS) | Svelte page: physical_id, QR placeholder, cut lines. Configurable cards per page (4–8). A4/Letter support. |
| QR-2 | QR code generation (server-side) | PHP QR library; generate PNG per token; inline in template. Handle empty hash (exclude), library failure. |
| QR-3 | Print API: selection + validation | `GET /api/admin/tokens/print` with ids/status/prefix. Validate IDs; skip deleted/invalid; return skip count. |
| QR-4 | Print API: large batch handling | Chunk 500 per request; stream PDF; progress indicator. Pagination for 1000+ tokens. |
| QR-5 | Tokens Index: Print selected UI | Checkbox selection; "Print selected" button; in_use/lost/damaged warnings before print. |
| QR-6 | PDF export for token sheet | Dompdf/spatie; stream download. Fallback to HTML on PDF failure. |
| QR-7 | Edge case tests | No tokens, invalid IDs, in_use/lost/damaged, deleted mid-print, large batch, empty hash, base URL missing. |
| QR-8 | Print instructions + cut lines | Document cut lines; optional "Print instructions" page or tooltip. Min QR size (~2cm) for scan reliability. |

**Dependencies:** QR-1 → QR-2; QR-1,2 → QR-3; QR-3 → QR-4, QR-6; QR-3 → QR-5; QR-7 validates QR-3,4,6; QR-8 with QR-1.

---

## 8. Documentation Updates

- [08-API-SPEC-PHASE1.md](../architecture/08-API-SPEC-PHASE1.md): Add `GET /api/admin/tokens/print` (or equivalent).
- [09-UI-ROUTES-PHASE1.md](../architecture/09-UI-ROUTES-PHASE1.md): Add print UI to Tokens page or new route.
- New: `docs/architecture/QR-TOKEN-PRINT-SPEC.md` — template dimensions, QR size, paper presets.
