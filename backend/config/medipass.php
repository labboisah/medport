<?php

return [
    // Working defaults pending legal/clinical owner approval; override per environment.
    'consent_max_hours' => (int) env('MEDIPASS_CONSENT_MAX_HOURS', 720),
    'share_token_minutes' => (int) env('MEDIPASS_SHARE_TOKEN_MINUTES', 10),
    // Placeholder freshness threshold; the real value is a clinical-owner policy.
    'stale_after_days' => (int) env('MEDIPASS_STALE_AFTER_DAYS', 365),
    'document_max_bytes' => (int) env('MEDIPASS_DOCUMENT_MAX_BYTES', 10 * 1024 * 1024),
];
