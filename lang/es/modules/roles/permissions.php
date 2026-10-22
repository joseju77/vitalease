<?php

return [
    /*
     * Standalone Spanish labels for every `App\Enums\Permission` value,
     * nested by resource then action so `labels.<permission value>`
     * resolves through dot notation. Wording follows the action vocabulary
     * of `resources/js/lib/permissionLabels.ts`.
     */
    'labels' => [
        'users' => [
            'manage' => 'Gestionar usuarios',
        ],
        'reports' => [
            'generate' => 'Generar reportes',
        ],
        'patients' => [
            'view' => 'Ver pacientes',
            'create' => 'Crear pacientes',
            'update' => 'Actualizar pacientes',
            'disable' => 'Deshabilitar y habilitar pacientes',
            'audit' => 'Consultar historial de cambios de pacientes',
        ],
        'consultations' => [
            'view' => 'Ver consultas',
            'create' => 'Crear consultas',
            'update' => 'Actualizar consultas',
            'void' => 'Anular consultas',
            'void-any' => 'Anular consultas de cualquier médico',
            'view-voided' => 'Ver consultas anuladas',
            'audit' => 'Consultar historial de cambios de consultas',
        ],
        'roles' => [
            'manage' => 'Gestionar roles',
        ],
        'inventory' => [
            'view' => 'Ver inventario',
            'create' => 'Crear en inventario',
            'update' => 'Actualizar inventario',
            'delete' => 'Eliminar de inventario',
        ],
    ],
];
