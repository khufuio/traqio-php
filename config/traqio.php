<?php

// Traqio — server SDK configuration (Laravel). Publish it with:
//   php artisan vendor:publish --tag=traqio-config

return [

    // The product's SERVER key (tq_sk_…), from Integrations → SDK in the Traqio
    // console. A secret: keep it in .env, never in a page or a mobile app.
    'secret_key' => env('TRAQIO_SECRET_KEY'),

    // Origin of the Traqio API.
    'endpoint' => env('TRAQIO_ENDPOINT', 'https://api.traqio.app'),

    // Timeout of one attempt, in seconds.
    'timeout' => (float) env('TRAQIO_TIMEOUT', 10),

    // Retries after a network error, a 429 or a 5xx (the same body is resent, so
    // nothing is counted twice).
    'max_retries' => (int) env('TRAQIO_MAX_RETRIES', 2),

    // Flags every call as development traffic: shown in the console's Debug view,
    // never counted, attributed, forwarded to ad networks or billed. Defaults to on
    // in the local and testing environments.
    'debug' => env('TRAQIO_DEBUG') ?? app()->environment('local', 'testing'),

];
