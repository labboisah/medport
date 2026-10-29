<?php

// Browsers may call the API only from the configured front-end origins. No wildcard and no cookies:
// the token travels in the Authorization header, so credentials are not needed.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Correlation-ID'],
    'exposed_headers' => ['X-Correlation-ID', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
