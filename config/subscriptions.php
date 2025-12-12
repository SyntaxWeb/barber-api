<?php

return [
    'plans' => [
        'mensal' => [
            'name' => 'Plano Mensal',
            'price' => 59.99,
            'months' => 1,
            'external_reference' => 'BarberAppMensal'
        ],
        'trimestral' => [
            'name' => 'Plano Trimestral',
            'price' => 159.99,
            'months' => 3,
            'external_reference' => 'BarberAppTrimestral',
        ],
        'anual' => [
            'name' => 'Plano Anual',
            'price' => 659.99,
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
