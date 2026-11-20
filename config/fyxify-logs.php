<?php

return [
    'enabled' => env('FYXIFY_LOGS_ENABLED', true),

    // fyxify-logs Node service and this app's schema name in fyxify_logs
    'url'     => env('FYXIFY_LOGS_URL', 'http://localhost:4000'),
    'key'     => env('FYXIFY_LOGS_KEY'),
    'project' => env('FYXIFY_LOGS_PROJECT'),
    'timeout' => (float) env('FYXIFY_LOGS_TIMEOUT', 2),

    // What gets sent is chosen per channel in config/logging.php ('driver' => 'fyxify' or 'fyxify' => true).
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
