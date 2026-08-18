<?php

return [
    'attributes' => [
        'name' => 'nombre',
        'presentation' => 'presentación',
        'concentration' => 'concentración',
        'dispensing_unit' => 'unidad de dispensación',
        'minimum_stock' => 'existencia mínima',
        'is_active' => 'activo',
        'quantity' => 'cantidad',
        'notes' => 'notas',
    ],

    'custom' => [
        'duplicate_medication' => 'Ya existe un medicamento con el mismo nombre, presentación y concentración.',
        'has_history' => 'No se puede eliminar un medicamento con movimientos o líneas de tratamiento registradas.',
        'adjustment_quantity_nonzero' => 'La cantidad del ajuste no puede ser cero.',
        'adjustment_notes_required' => 'Debes explicar el motivo del ajuste.',
    ],
];
