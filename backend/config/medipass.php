<?php

return [
    // Working defaults pending legal/clinical owner approval; override per environment.
    'consent_max_hours' => (int) env('MEDIPASS_CONSENT_MAX_HOURS', 720),
    'share_token_minutes' => (int) env('MEDIPASS_SHARE_TOKEN_MINUTES', 10),
];
