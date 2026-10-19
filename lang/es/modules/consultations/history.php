<?php

return [
    'sections' => [
        'consultation' => 'consulta',
        'vital_signs' => 'signos vitales',
        'physical_examination' => 'exploración física',
        'regulation' => 'regulación',
        'treatment' => 'tratamiento',
    ],

    'events' => [
        'created' => 'Creación',
        'updated' => 'Modificación',
        'voided' => 'Anulación',
        'restored' => 'Restauración',
    ],

    'medical_state' => [
        1 => 'Bueno',
        2 => 'Regular',
        3 => 'Grave',
        4 => 'Crítico',
        5 => 'Reservado',
    ],

    'medical_classification' => [
        1 => 'Traumatología',
        2 => 'Otorrinolaringología',
        3 => 'Respiratorio',
        4 => 'Gastroenterología',
        5 => 'Oftalmología',
        6 => 'Cardiología',
        7 => 'Dermatología',
        8 => 'Neurología',
        9 => 'Endocrinología',
        10 => 'Psiquiatría',
    ],

    'transfer_type' => [
        1 => 'Instituto',
        2 => 'Servicios de Salud Municipales',
        3 => 'Medios propios',
    ],
];
