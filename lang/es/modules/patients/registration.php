<?php

return [
    'custom' => [
        'enrollment_xor' => 'Debe indicar una inscripción registrada en el catálogo o una inscripción externa, pero no ambas ni ninguna.',
        'family_medical_unit_xor' => 'Debe indicar una unidad médica familiar registrada en el catálogo o su nombre si no aparece en la lista, pero no ambas ni ninguna.',
        'neighborhood_zip_code_mismatch' => 'La colonia seleccionada no pertenece al código postal indicado.',
        'other_ailments_empty' => 'Debe indicar al menos una cirugía, alergia u otro padecimiento cuando se envían otros padecimientos.',
        'gynecological_history_not_applicable' => 'El historial ginecológico solo aplica cuando el sexo al nacer es Femenino.',
        'pap_smear_pairing' => 'La fecha y el resultado del último papanicolaou deben enviarse juntos o no enviarse.',
        'pregnancy_counts_exceeded' => 'La suma de partos vaginales, cesáreas y abortos no puede superar el número de embarazos.',
    ],
];
