<?php

use AdeildoJr\HttpLogs\Models\HttpRequest;

return [
    'enabled' => env('HTTP_LOGS_ENABLED', true),

    // Set these before running the published migration.
    'table_name' => env('HTTP_LOGS_TABLE', 'http_requests'),
    'connection' => env('HTTP_LOGS_CONNECTION'),
    'model' => HttpRequest::class,

    'capture' => [
        'request_body' => true,
        'response_body' => true,
        'request_headers' => true,
        'response_headers' => true,
    ],

    // Case insensitive; hyphens and underscores are equivalent.
    'redact_headers' => [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'api-key', 'x-auth-token', 'x-csrf-token', 'x-xsrf-token',
        'location', 'referer', 'referrer',
    ],
    'redact_fields' => [
        'password', 'password_confirmation', 'current_password',
        'token', 'access_token', 'refresh_token', 'id_token',
        'client_secret', 'secret', 'api_key', 'authorization',
        'cookie', 'set_cookie', 'csrf_token', 'xsrf_token',
    ],

    // Bound each JSON column; oversized data is replaced, never sliced. Minimum: 19 bytes.
    'max_payload_bytes' => 16 * 1024,
    'max_url_bytes' => 2048, // Minimum: 11 bytes for the discard marker.
    'max_depth' => 20, // Hard maximum: 100.

    // Applied only when model:prune is explicitly run or scheduled. <= 0 disables pruning.
    'retention_days' => 30,
];
