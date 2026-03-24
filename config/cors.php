<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(
            ',',
            env(
                'CORS_ALLOWED_ORIGINS',
                'http://localhost:4001,http://127.0.0.1:4001,https://barberapp.syntaxweb.com.br,https://api-barberapp.syntaxweb.com.br,https://syntaxatendimento.com.br,https://api-atendimento.syntaxatendimento.com.br'
            )
        )
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
