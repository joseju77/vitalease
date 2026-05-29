<?php

return [
    'validation' => [
        'email' => [
            'required' => 'Debes ingresar un correo electrónico.',
            'email' => 'Dirección de correo electrónico inválida.',
        ],
        'password' => [
            'required' => 'Debes ingresar una contraseña.',
            'invalid' => 'Contraseña inválida.',
        ],
    ],
    'errors' => [
        'invalid_credentials' => 'No se pudo iniciar sesión. Verifica tus credenciales.',
        'rate_limited' => 'Has realizado demasiados intentos. Intenta de nuevo más tarde.',
        'google_auth_failed' => 'No pudimos iniciar sesión con Google. Intenta de nuevo.',
        'google_missing_email' => 'No pudimos obtener tu correo electrónico desde Google.',
    ],
];
