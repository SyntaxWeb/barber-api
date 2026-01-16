<?php

return [
    'plans' => [
        'mensal' => [
            'name' => 'Plano Mensal',
            'price' => 30.00,
            'months' => 1,
            'external_reference' => 'BarberAppMensal'
        ],
        'trimestral' => [
            'name' => 'Plano Trimestral',
            'price' => 90.00,
            'months' => 3,
            'external_reference' => 'BarberAppTrimestral',
        ],
        'anual' => [
            'name' => 'Plano Anual',
            'price' => 360.00,
            'months' => 12,
            'external_reference' => 'BarberAppAnual',
        ],
    ],
    'statuses' => [
        'ativo',
        'pendente',
        'suspenso',
        'cancelado',
    ],
];
