<?php

declare(strict_types=1);

return [
    'name'        => env('APP_NAME', 'Life Drawing Randburg'),
    'env'         => env('APP_ENV', 'local'),
    'url'         => env('APP_URL', 'http://localhost/lifedrawing'),
    'base_path'   => env('APP_BASE_PATH', '/lifedrawing/public'),
    'timezone'    => 'Africa/Johannesburg',

    // The community WhatsApp group. Announcements and day-to-day chat live
    // there; booking and cancelling happen on the site. Public on the FAQ
    // anyway, so the real invite is the default and production needs no .env edit.
    'whatsapp_url' => env('APP_WHATSAPP_URL', 'https://chat.whatsapp.com/DIuJ2yPbjpG350Ovjxj1Hx'),

    // Sitter-queue auto-completion, OFF by default on purpose.
    //
    // On a database that still holds the backlog, the first facilitator page
    // load after deploy would sweep years of stuck entries at once. The 30-day
    // notification cutoff stops most of the mail, but a sitter stuck from last
    // week is inside that window and would be emailed. So: deploy with this
    // off, run tools/fix-sitter-queue.php, then turn it on.
    'sitter_auto_complete' => env('APP_SITTER_AUTO_COMPLETE', false),
    'locale'      => 'en_ZA',

    // Modules to load (order matters for migration sequence)
    'modules' => [
        'lifedrawing',
    ],
];
