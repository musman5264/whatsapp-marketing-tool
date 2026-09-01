<?php

return [

    'logging' => [
        // Master switch. Set API_LOGGING_ENABLED=false to disable all API
        // request logging without a deploy.
        'enabled' => env('API_LOGGING_ENABLED', true),

        // Fraction of successful (2xx/3xx) responses whose request+response
        // bodies are stored. Errors (>=400) are always stored at 100%.
        'success_sample_rate' => (float) env('API_LOGGING_SAMPLE_RATE', 0.1),

        // Each stored body is truncated to this many bytes.
        'max_body_bytes' => 16384,

        // Metadata rows are deleted after this many days.
        'retention_days' => 90,

        // Payload columns (bodies, headers, query) are nulled after this many
        // days; the row survives as metadata until retention_days.
        'payload_retention_days' => 14,

        // Hard ceiling on total rows. The prune job deletes the oldest rows
        // beyond this count regardless of age.
        'max_rows' => 5_000_000,

        // Request/response body keys (and header names) whose values are
        // replaced with "[redacted]" before storage. Case-insensitive.
        'redact_keys' => [
            'password', 'password_confirmation', 'token', 'secret',
            'authorization', 'api_key', 'apikey', 'secret_key', 'access_token',
            'refresh_token', 'client_secret', 'otp', 'pin', 'cvv',
        ],
    ],

];
