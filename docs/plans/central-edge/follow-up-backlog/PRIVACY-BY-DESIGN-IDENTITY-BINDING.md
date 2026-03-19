# FlexiQueue — Identity Binding Washout: Developer Spec

**What this is:** Full replacement of the old ID document system with phone-based client identity. The old system (ClientIdDocument, ClientIdDocumentService, ClientIdNumberHasher, ClientIdTypes, all related routes and requests) is deleted entirely. DB can be reseeded. Build new alongside deletions.

---

## BEFORE WRITING ANY CODE — Lock the normalization rules

These are the rules for `MobileNormalizer`. They **cannot change** after the first row is stored without a full re-hash migration of every `mobile_hash` in the database.

| Input | Normalized Output | Rule |
|-------|-------------------|------|
| 09171234567 | 09171234567 | Keep as-is |
| +639171234567 | 09171234567 | Strip +63, prepend 0 |
| 0917 123 4567 | 09171234567 | Strip spaces |
| 0917-123-4567 | 09171234567 | Strip dashes |
| (0917) 123-4567 | 09171234567 | Strip parens and dashes |

**Mask format for display:** `0917-***-**67` (show first 4 digits, mask middle, show last 2).

Write `MobileNormalizerTest` testing all five input formats **before** implementing anything else. The hash is SHA-256 of `normalize()` output — a bug here corrupts every stored hash.

---

## FILES TO DELETE ENTIRELY

Do not refactor. **Delete.**

- `app/Support/ClientIdNumberHasher.php`
- `app/Support/ClientIdTypes.php`
- `app/Services/ClientIdDocumentService.php`
- `app/Models/ClientIdDocument.php`
- `app/Http/Controllers/Api/Admin/ClientIdDocumentAdminController.php`
- `app/Http/Controllers/Api/Admin/ClientIdDocumentRevealController.php`
- `app/Http/Requests/AttachClientIdDocumentRequest.php`
- `app/Http/Requests/ReassignClientIdDocumentRequest.php`
- `app/Http/Requests/ClientLookupByIdRequest.php`
- `app/Http/Requests/Admin/RevealClientIdDocumentRequest.php`
- `app/Exceptions/DuplicateClientIdDocumentException.php`

---

## SCHEMA CHANGES

DB is being reseeded so these are **fresh migrations**, not alter migrations.

### Drop these tables

- `client_id_documents` — gone entirely
- `client_id_audit_log` — drop and rebuild with new shape (see below)

### clients table

Add two columns:

| Column | Type | Notes |
|--------|------|-------|
| `mobile_encrypted` | text nullable | AES-256 encrypted |
| `mobile_hash` | string(64) nullable | SHA-256 of normalized mobile |

One mobile per client. No array, no second number. Staff/supervisor can update it via a dedicated endpoint (Section 6 below).

### identity_registrations table

**Remove:**

- `id_type`
- `id_number_encrypted`
- `id_number_last4`
- `id_verified_at`
- `id_verified_by_user_id`

**Add:**

| Column | Type | Notes |
|--------|------|-------|
| `mobile_encrypted` | text nullable | |
| `mobile_hash` | string(64) nullable | |
| `id_verified` | boolean default false | Staff manual toggle |
| `id_verified_by_user_id` | foreignId nullable -> users | |
| `id_verified_at` | timestamp nullable | |

`id_verified` is now a simple boolean that staff toggles manually. There is no ID scan or comparison. Staff physically checks the person and clicks Verified.

### client_id_audit_log — drop and recreate

| Column | Type |
|--------|------|
| `id` | bigIncrements |
| `client_id` | foreignId -> clients |
| `identity_registration_id` | foreignId nullable -> identity_registrations |
| `staff_user_id` | foreignId -> users |
| `action` | string(50) — "phone_reveal" \| "phone_update" |
| `mobile_last2` | string(2) nullable — last 2 digits for trace only |
| `reason` | text nullable |
| `created_at` | timestamp useCurrent |

No `client_id_document_id`. No `id_type`. No `id_last4`. This table now only tracks phone reveal and phone update events.

---

## NEW FILES TO CREATE

### app/Support/MobileNormalizer.php

Single static method `normalize(string $mobile): string`. Applies the rules from the table above. **Write the test first.**

### app/Services/MobileCryptoService.php

| Method | Behavior |
|--------|----------|
| `hash(string $mobile): string` | SHA-256 of normalize($mobile) |
| `encrypt(string $mobile): string` | Crypt::encryptString(normalize($mobile)) |
| `decrypt(string $encrypted): string` | Crypt::decryptString($encrypted) |
| `mask(string $mobile): string` | Returns "0917-***-**67" format |

Use Laravel Crypt (AES-256-CBC). Write `MobileCryptoServiceTest` covering hash/encrypt/decrypt roundtrip and mask output **before** using this service anywhere else.

---

## CHANGES TO EXISTING FILES

### app/Models/Client.php

- Add `mobile_encrypted`, `mobile_hash` to `$fillable`
- Keep `idDocuments()` relation only if you want to leave a comment explaining it's been removed; otherwise delete the method

### app/Models/IdentityRegistration.php

- Remove `id_type`, `id_number_encrypted`, `id_number_last4`, `id_verified_at`, `id_verified_by_user_id` from `$fillable`
- Add `mobile_encrypted`, `mobile_hash`, `id_verified`, `id_verified_by_user_id`, `id_verified_at` to `$fillable`
- Keep `idVerifiedBy()` relation (still valid, just now tracks who toggled the boolean)
- Remove `id_verified_at` datetime cast; keep as nullable timestamp

### app/Models/ClientIdAuditLog.php

Rebuild `$fillable` to match new schema:

- `client_id`, `identity_registration_id`, `staff_user_id`, `action`, `mobile_last2`, `reason`, `created_at`
- Remove `client_id_document_id`, `id_type`, `id_last4`. Remove `document()` relation.

### app/Support/ClientBindingSource.php

- Remove `EXISTING_ID_DOCUMENT` and `NEW_ID_DOCUMENT` constants and from the ALL array and REQUIRES_ID_DOCUMENT array
- Replace with:

```php
const PHONE_MATCH = 'phone_match';   // found via validated phone search
const NEW_CLIENT  = 'new_client';    // newly created during this triage
```

- Update `requiresIdDocument()` — this method can be removed entirely or renamed to `requiresPhoneMatch()` if the binding service needs it
- Update `validationRules()` to reflect the new constants

### app/Http/Requests/BindSessionRequest.php

- Remove `identity_registration_request.id_type` and `identity_registration_request.id_number` rules
- Add `identity_registration_request.mobile` — nullable|string (required when submitting a registration from public triage)
- Remove `client_binding.id_document_id` rule entirely
- Keep `client_binding.client_id` and `client_binding.source` rules; source now validates against the new ClientBindingSource constants

### app/Http/Requests/StoreClientRequest.php

- Remove `id_document`, `id_document.id_type`, `id_document.id_number` rules
- Add `mobile` — nullable|string (will be encrypted/hashed in the controller)

### app/Http/Requests/ClientSearchRequest.php

- No changes needed — name + birth_year search stays as-is

### app/Services/ClientService.php

- Update `createClient()` to accept optional mobile:

```php
public function createClient(string $name, int $birthYear, ?string $mobile = null): Client
```

When `$mobile` is provided, encrypt and hash it via `MobileCryptoService` before storing.

- Add new method:

```php
public function searchClientsByPhone(string $mobile): ?Client
```

Hashes the mobile via `MobileCryptoService::hash()`, queries `WHERE mobile_hash = ?`, returns single client or null. This is the validated match — exact hash match only, no fuzzy search.

### app/Services/IdentityBindingService.php

**Complete rewrite.** Remove all `ClientIdDocumentService` dependency and `id_document_id` logic.

**New `resolve()` logic:**

1. If `client_binding` is null and binding is required → throw `IdentityBindingException`
2. If `client_binding` is null and binding is optional → return `['client_id' => null, 'metadata' => null]`
3. If `client_binding` is present → get `client_id` and `source` from payload
4. Find the `Client` by `client_id`. If not found and binding required → throw. If not found and optional → return null
5. No `id_document_id` check at all. Trust is now: the client_id came from a phone-based validated search on the frontend. Just verify the client exists
6. Build metadata with `client_id`, `binding_mode`, `binding_source`, `binding_request_source`

### app/Http/Controllers/Api/ClientController.php

- Remove `ClientIdDocumentService` dependency entirely
- Remove `lookupById()` method — **delete it**
- Remove `attachIdDocument()` method — **delete it**
- Update `search()` — remove `has_id_document` from response; add `mobile_masked` (use `MobileCryptoService::mask()` on the decrypted value). Do NOT return raw mobile
- Update `store()` — accept optional `mobile` in request; call updated `ClientService::createClient()`; remove `id_document` block entirely

**Add new method `searchByPhone()`:**

```
POST /api/clients/search-by-phone
Body: { mobile: string }
Auth: staff (authenticated)
Response: { match_status: "existing"|"not_found", client: { id, name, birth_year, mobile_masked } | null }
```

Call `ClientService::searchClientsByPhone()`. Never return raw mobile. Return masked only.

### app/Http/Controllers/Api/IdentityRegistrationController.php

| Method | Change |
|--------|--------|
| **index()** | Remove `id_type`, `id_number_last4` from response. Add `mobile_masked` (decrypt then mask, or null if no mobile stored) |
| **direct()** | Remove `id_type`, `id_number`, `id_number_encrypted`, `id_number_last4`, `id_verified_at`/`id_verified_by_user_id` logic. Accept optional `mobile`. Store `mobile_encrypted` and `mobile_hash` via `MobileCryptoService`. Remove the `ClientIdDocumentService::createForClient()` call entirely. Remove `DuplicateClientIdDocumentException` catch |
| **possibleMatches()** | No changes to the method logic. Remove `has_id_document` and `id_documents_count` from the response shape |
| **verifyId()** | **Delete this method entirely.** There is no ID scan anymore |
| **accept()** | Remove `register_id` validation and logic block. Remove the block that decrypts `id_number_encrypted` and calls `createForClient()`. Remove `DuplicateClientIdDocumentException` catch. The accept flow is now: link `client_id` to the registration, update name/birth_year/client_category, update session if one exists. That's it |

### app/Http/Controllers/Api/PublicTriageController.php

| Method | Change |
|--------|--------|
| **bind()** | The `identity_registration_request` block has a candidate deduplication loop matching on `id_number_last4` + `id_number_encrypted`. **Delete that entire loop.** Replace with: if `mobile` is present in the registration request, hash it and check for an existing pending registration with the same `mobile_hash` for this program. If found, reuse it. If not found, create new. Store `mobile_encrypted` and `mobile_hash` on the new registration. Remove all `Crypt::encryptString($idNumberRaw)` and `getLast4FromRawNumber()` calls |
| **publicLookupById()** | **Delete this method entirely.** Phone-based search for public triage is intentionally not available. Public triage is scan-only (QR token). Unknown tokens are rejected. No public "find me by phone" endpoint |

### app/Http/Controllers/Api/Admin/ClientAdminController.php

The `destroy()` method currently blocks deletion if an audit log exists on `client_id`. That logic stays — keep it. No other changes needed.

### app/Http/Controllers/Admin/ClientPageController.php

| Method | Change |
|--------|--------|
| **index()** | Remove `id_documents_count` from the client map. Add `mobile_masked` (nullable) |
| **show()** | Remove the entire `$idDocuments` block. Replace with just the mobile masked value from the client. The "ID Documents" section is gone. Instead show: masked mobile (if set), and a Reveal button + an Update button (staff/supervisor only for update, admin only for reveal — see Section 6 below) |

---

## NEW ENDPOINTS TO ADD

### Phone Reveal (admin only)

```
POST /api/clients/{client}/reveal-phone
Auth: admin only
Body: { reason: string, confirm: true }
```

1. Decrypt `client.mobile_encrypted` via `MobileCryptoService::decrypt()`
2. Write audit log: `action = phone_reveal`, `mobile_last2 = last 2 digits`, `staff_user_id`, `reason`
3. Return `{ mobile: string }` — full number, one-time display only

### Phone Update (staff or supervisor)

```
PUT /api/clients/{client}/mobile
Auth: staff or supervisor
Body: { mobile: string, reason: string }
```

1. Normalize and validate the new mobile — reject if blank
2. Hash new mobile, check no other client has the same `mobile_hash` → 409 if duplicate
3. Write audit log: `action = phone_update`, `mobile_last2 = last 2 of new number`, `staff_user_id`, `reason`
4. Update `client.mobile_encrypted` and `client.mobile_hash`
5. Return `{ mobile_masked: string }`

### Identity Registration Phone Reveal (staff only, during accept flow)

```
POST /api/identity-registrations/{identityRegistration}/reveal-phone
Auth: staff
Body: { reason: string }
```

Same as client reveal but reads from `identity_registration.mobile_encrypted`. Logs with `identity_registration_id` instead of just `client_id`.

---

## ROUTES TO REMOVE

```
POST   /api/public/clients/lookup-by-id
POST   /api/clients/lookup-by-id
POST   /api/clients/{client}/id-documents
DELETE /api/client-id-documents/{doc}
POST   /api/client-id-documents/{doc}/reassign
POST   /api/client-id-documents/{doc}/reveal
POST   /api/identity-registrations/{id}/verify-id
```

---

## ROUTES TO ADD

```
POST /api/clients/search-by-phone
POST /api/clients/{client}/reveal-phone
PUT  /api/clients/{client}/mobile
POST /api/identity-registrations/{id}/reveal-phone
```

---

## IMPLEMENTATION ORDER

Do these in sequence. **Do not mix phases.**

1. **Write tests first** — MobileNormalizerTest, MobileCryptoServiceTest. Get them green before touching anything else
2. Create MobileNormalizer and MobileCryptoService. Tests must pass
3. **Schema** — new migrations for clients, identity_registrations, client_id_audit_log. Reseed
4. **Delete files** — everything in the delete list above. Remove their route registrations
5. Update ClientBindingSource — swap out the ID document constants
6. Update models — Client, IdentityRegistration, ClientIdAuditLog
7. Update ClientService — createClient() with optional mobile, add searchClientsByPhone()
8. Rewrite IdentityBindingService — remove all ID document logic
9. Update BindSessionRequest and StoreClientRequest
10. Update PublicTriageController::bind() — remove candidate loop, add phone dedup
11. Update IdentityRegistrationController — remove verifyId(), gut direct() and accept()
12. Update ClientController — remove lookupById(), attachIdDocument(), add searchByPhone()
13. Add new endpoints — reveal phone, update phone, IR reveal phone
14. Update ClientPageController — remove ID documents block, show masked mobile
15. **Frontend** — update triage binding UI, pending registrations list, client admin detail page
16. **Regression** — PublicTriageTest, IdentityRegistrationApiTest, ClientIdentityApiTest — update all to use mobile instead of ID fields

---

## STAKEHOLDER RULE (non-negotiable)

**One mobile number per client.** No multiple numbers, no history of old numbers stored in a documents-style table. If a client's number changes, staff or supervisor updates it via `PUT /api/clients/{client}/mobile`. The audit log records who changed it and when. The old number is not retained anywhere — only the `mobile_last2` trace in the audit log.
