<?php

declare(strict_types=1);

return [
    'name'        => env('APP_NAME', 'Life Drawing Randburg'),
    'env'         => env('APP_ENV', 'local'),
    'url'         => env('APP_URL', 'http://localhost/lifedrawing'),
    'base_path'   => env('APP_BASE_PATH', '/lifedrawing/public'),
    'timezone'    => 'Africa/Johannesburg',
    'locale'      => 'en_ZA',

    // Modules to load (order matters for migration sequence)
    'modules' => [
        'lifedrawing',
    ],
];
