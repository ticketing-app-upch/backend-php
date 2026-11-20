<?php

return [
    // Vacío: las URLs públicas usan APP_URL. Configurar al integrar Angular.
    'frontend_url' => env('FRONTEND_URL'),
    'venue_timezone' => 'America/Lima',
    'virtual_queue' => [
        'url' => env('VIRTUAL_QUEUE_URL'),
        'service_token' => env('VIRTUAL_QUEUE_SERVICE_TOKEN'),
        'pass_public_key' => env('VIRTUAL_QUEUE_PASS_PUBLIC_KEY'),
    ],
];
