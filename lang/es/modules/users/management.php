<?php

return [
    'validation' => [
        'name' => [
            'required' => 'Debes ingresar un nombre.',
            'max' => 'El nombre no puede tener más de 255 caracteres.',
        ],
        'email' => [
            'required' => 'Debes ingresar un correo electrónico.',
            'email' => 'Dirección de correo electrónico inválida.',
            'unique' => 'Ya existe un usuario con este correo electrónico.',
        ],
        'password' => [
            'required' => 'Debes ingresar una contraseña.',
            'min' => 'La contraseña debe tener al menos 8 caracteres.',
        ],
        'has_access' => [
            'required' => 'Debes indicar si el usuario tiene acceso.',
            'boolean' => 'El valor de acceso es inválido.',
        ],
        'roles' => [
            'present' => 'Debes indicar los roles del usuario, aunque sea una lista vacía.',
            'array' => 'Los roles deben enviarse como una lista.',
            'exists' => 'Uno de los roles seleccionados no existe.',
        ],
        'permissions' => [
            'present' => 'Debes indicar los permisos del usuario, aunque sea una lista vacía.',
            'array' => 'Los permisos deben enviarse como una lista.',
            'enum' => 'Uno de los permisos seleccionados no es válido.',
        ],
    ],
];
