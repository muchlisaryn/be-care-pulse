<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Origin frontend yang boleh mengakses API. Tambahkan host/IP baru di
    | CORS_ALLOWED_ORIGINS (.env, dipisah koma) atau langsung di array default.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://10.12.12.192',
        'http://10.12.12.192:3000',
    ])))),

    // Semua port di host 10.12.12.192 (dev server Next.js kadang pindah port).
    'allowed_origins_patterns' => ['#^http://10\.12\.12\.192(:\d+)?$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
