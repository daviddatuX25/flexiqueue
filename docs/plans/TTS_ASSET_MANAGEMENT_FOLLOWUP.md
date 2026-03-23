# TTS Asset Management Follow-up (Deferred)

## Purpose

Capture the next planning item for a modular, reusable TTS asset layer that can scale across token and station generation, downloads, and edge-sync compatibility.

## Why this follow-up exists

Current work aligns token/station UI semantics, but backend asset lifecycle concerns should be centralized before expanding download and sync-heavy workflows.

## Proposed planning scope

1. Shared presenter/utilities for token/station per-language TTS status mapping.
2. Unified download file naming convention:
   - deterministic names by entity (`token`/`station`), language, and revision/version
   - collision-safe strategy for retries and regeneration
   - phrase-derived naming/keying for idempotency:
     - use the exact generated phrase as the canonical identity input (normalized)
     - keep human-readable filename slug examples like `eng_calling_a_1`
     - append a short stable hash of the canonical phrase + voice + rate to avoid collisions and preserve lookup safety
     - allow cross-language reuse detection when canonical phrase text is identical (if voice/provider policy allows reuse)
   - maintain reverse mapping so storage detection can reliably prove an existing file belongs to a specific generated phrase/config
3. Singleton/idempotent generation safeguards:
   - prevent duplicate generation runs for the same asset key
   - predictable behavior when regenerate is requested while generation is in progress
4. Storage lifecycle management:
   - write path conventions
   - retention/cleanup policy for replaced assets
   - metadata contract for status, failure reason, and timestamps
5. Edge compatibility prework:
   - ensure asset metadata and naming are compatible with `docs/edge-sync-pairing.md`
   - define sync-safe references for token/station language assets

## Suggested deliverable

A design plan documenting contracts, migration approach, and phased rollout for token + station TTS asset handling.

## Alignment note with display gapless plan

The display-focused plan `docs/plans/DISPLAY_TTS_GAPLESS_STITCH_PRELOAD_PLAN.md` is intentionally scoped to playback orchestration and retrieval contracts, and should remain compatible with this follow-up by keeping these boundaries:

1. Do not couple frontend preload keys to storage filenames.
2. Keep retrieval endpoint contracts stable while asset naming/versioning evolves.
3. Keep preload cache identity abstract (`token/station/lang/revision-ready`) so future asset revision metadata can be adopted without frontend architecture changes.
4. Keep station/token stream controllers as transport-only; asset ownership, idempotency, and lifecycle remain in the asset-management layer.
