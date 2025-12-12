<?php

return [
    'plans' => [
        'mensal' => [
            'name' => 'Plano Mensal',
            'price' => 99.90,
            'months' => 1,
            'external_reference' => 'BarberAppMensal'
        ],
        'trimestral' => [
            'name' => 'Plano Trimestral',
            'price' => 279.90,
            'months' => 3,
        ],
        'anual' => [
            'name' => 'Plano Anual',
            'price' => 999.00,
            'months' => 12,
        ],
    ],
    'statuses' => [
        'ativo',
        'pendente',
        'suspenso',
        'cancelado',
    ],
];
