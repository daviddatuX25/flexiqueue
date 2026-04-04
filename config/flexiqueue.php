<?php

return [
    /*
    | When false, GET /triage redirects to /station with a flash message so the primary
    | triage entry is the staff footer QR scan (device refactor Phase 1 / Slice B).
    | Default true preserves legacy behavior.
    */
    /*
     * Default true when unset. Use FILTER_VALIDATE_BOOLEAN so .env "false" / "0" parse correctly
     * (invalid FILTER_VALIDATE_BOOL usage previously coerced env incorrectly in tests).
     */
    'staff_triage_page_enabled' => filter_var(env('FEATURE_STAFF_TRIAGE_PAGE', '1'), FILTER_VALIDATE_BOOLEAN),
];
