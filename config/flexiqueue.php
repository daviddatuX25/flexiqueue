<?php

return [
    'staff_triage_page_enabled' => filter_var(env('FEATURE_STAFF_TRIAGE_PAGE', '1'), FILTER_VALIDATE_BOOLEAN),
    'latest_edge_app_version' => env('LATEST_EDGE_APP_VERSION'),
];
