<?php

return [
    // First Super Admin for `php artisan db:seed` (PlatformSeeder). Prefer `php artisan admin:create-super`,
    // which asks for the password in the terminal so it never lands in .env.
    'super_admin' => [
        'login' => env('SUPER_ADMIN_LOGIN', ''),
        'password' => env('SUPER_ADMIN_PASSWORD', ''),
    ],
];
