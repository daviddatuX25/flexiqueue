# TTS Phase 3 Rollout and Rollback Playbook

## Scope

Operational rollout checklist for Phase 3 hardening work:

- compatibility regression coverage
- runtime diagnostics
- lifecycle cleanup scheduling
- edge/package canonical reference alignment

## Pre-Deploy Checks

1. PHPUnit gates pass:
   - `tests/Feature/Api/TtsControllerTest.php`
   - `tests/Feature/Api/Admin/ProgramPackageExportTest.php`
   - `tests/Feature/Api/Admin/TokenControllerTest.php`
   - `tests/Feature/Api/Admin/StationControllerTest.php`
   - `tests/Unit/Services/Tts/*`
2. Verify no breaking route changes on:
   - `GET /api/public/tts/token/{token}`
   - `GET /api/public/tts/station/{station}/{lang}`
3. Verify diagnostics are disabled by default:
   - `TTS_RUNTIME_DIAGNOSTICS_ENABLED=false`
4. Validate dry-run cleanup:
   - `php artisan tts:cleanup-superseded --dry-run --days=14 --limit=200`

## Staged Rollout

1. Deploy backend with diagnostics disabled.
2. Run smoke checks:
   - token retrieval legacy path
   - token retrieval revision-ready fallback path (`tts_settings.languages.en.audio_path`)
   - station retrieval path for `en|fil|ilo`
3. Enable diagnostics in one environment:
   - `TTS_RUNTIME_DIAGNOSTICS_ENABLED=true`
4. Monitor logs for:
   - `tts.generation_lock.contended`
   - `tts.asset.lifecycle.failed`
   - rising fallback rates in display telemetry snapshots
5. Enable scheduled cleanup only after 24h stable metrics.

## Acceptance Thresholds

- No increase in 404/503 for public TTS retrieval routes.
- Lock contention ratio does not exceed 10% of generation attempts for sustained traffic.
- Fallback counters trend stable or down after warm-up period.
- Cleanup command deletes only superseded assets older than policy window.

## Rollback Criteria

Rollback immediately if any of the following occurs:

- retrieval regressions on token/station endpoints
- unexpected deletion of active TTS files
- repeated lock contention causing generation starvation
- edge import failures due to package contract mismatch

## Rollback Steps

1. Disable diagnostics:
   - `TTS_RUNTIME_DIAGNOSTICS_ENABLED=false`
2. Disable cleanup schedule:
   - comment schedule entry or set scheduler guard in release branch
3. Revert deployment to previous stable release.
4. Re-run regression suite and confirm recovery.
5. Capture incident notes with failing payload examples and affected entity IDs.
