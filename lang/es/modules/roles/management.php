<?php

return [
    'validation' => [
        'name' => [
            'required' => 'Debes ingresar un nombre para el rol.',
            'max' => 'El nombre no puede tener más de 255 caracteres.',
            'unique' => 'Ya existe un rol con este nombre.',
        ],
        'permissions' => [
            'present' => 'Debes indicar los permisos del rol, aunque sea una lista vacía.',
            'array' => 'Los permisos deben enviarse como una lista.',
            'enum' => 'Uno de los permisos seleccionados no es válido.',
        ],
    ],
];
