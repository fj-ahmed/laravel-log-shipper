<?php

return [
    'enabled' => env('LOG_SHIPPER_ENABLED', true),

    // Collector service: batches are POSTed to {url}/ingest/{project} with an X-Api-Key header
    'url'     => env('LOG_SHIPPER_URL', 'http://localhost:4000'),
    'key'     => env('LOG_SHIPPER_KEY'),
    'project' => env('LOG_SHIPPER_PROJECT'),
    'timeout' => (float) env('LOG_SHIPPER_TIMEOUT', 2),

    // Request header naming the calling client (stored as 'source'); falls back to the host
    'source_header' => env('LOG_SHIPPER_SOURCE_HEADER', 'X-Source'),

    // What gets sent is chosen per channel in config/logging.php ('driver' => 'shipper' or 'shipper' => true).
    // The settings below only tune those channels.

    // 'runs' channel: wrapper commands that only spawn the real ones (Str::is patterns)
    'except_commands' => [
        'schedule:run', 'schedule:work', 'schedule:finish', 'queue:work', 'queue:listen', 'horizon*',
        'list', 'help', 'package:discover', 'vendor:publish', 'config:*', 'route:*', 'view:*', 'optimize*', 'event:*',
    ],

    // Masked everywhere: input, output, headers, URL, log messages and context.
    // Keys match case-insensitively by substring; exact_keys must match the whole key.
    'mask_keys' => [
        'password', 'passwd', 'token', 'secret', 'authorization', 'api_key', 'apikey', 'api-key', 'cookie',
        'card_number', 'cardnumber', 'cvv', 'cvc', 'ssn', 'driving_license', 'state_license', 'birth_date',
        'private_key', 'xsrf', 'csrf', 'signature',
    ],
    'mask_exact_keys' => ['otp', 'pin', 'dob', 'code_verifier'],

    // Response bodies larger than this are truncated (bytes)
    'max_output' => 262144,

    // Auth guards checked (in order) for user_id; only already-resolved users are read, no extra queries
    'guards' => [null, 'api', 'admin', 'web'],
];
