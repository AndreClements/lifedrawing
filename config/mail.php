<?php

declare(strict_types=1);

return [
    'host'         => env('MAIL_HOST', 'localhost'),
    'port'         => (int) env('MAIL_PORT', 587),
    'username'     => env('MAIL_USERNAME', ''),
    'password'     => env('MAIL_PASSWORD', ''),
    'encryption'   => env('MAIL_ENCRYPTION', 'tls'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@lifedrawingrandburg.co.za'),
    'from_name'    => env('MAIL_FROM_NAME', 'Life Drawing Randburg'),
];
