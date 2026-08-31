<?php

return [
    'providers' => [
        'mercado_pago' => [
            'name' => 'Mercado Pago',
            'provider' => 'mercado_pago',
            'slug' => 'mercado-pago',
            'type' => 'payment',
            'enabled' => true,
            'description' => 'Receba pagamentos dos seus agendamentos diretamente na sua conta Mercado Pago.',
            'settings' => [
                'pix_enabled' => true,
                'online_payments_enabled' => true,
            ],
            'oauth' => [
                'client_id' => env('MERCADO_PAGO_CONNECT_CLIENT_ID'),
                'client_secret' => env('MERCADO_PAGO_CONNECT_CLIENT_SECRET'),
                'redirect_uri' => env('MERCADO_PAGO_CONNECT_REDIRECT_URI', env('APP_URL') . '/api/integrations/mercado-pago/oauth/callback'),
                'authorize_url' => env('MERCADO_PAGO_AUTHORIZE_URL', 'https://auth.mercadopago.com.br/authorization'),
            ],
            'webhook_secret' => env('MERCADO_PAGO_CONNECT_WEBHOOK_SECRET'),
        ],
    ],
];
