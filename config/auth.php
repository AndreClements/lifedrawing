<?php

declare(strict_types=1);

return [
    // Session configuration
    'session_name'     => 'ldr_session',
    'session_lifetime' => 7200, // 2 hours

    // Password hashing
    'hash_algo'  => PASSWORD_BCRYPT,
    'hash_cost'  => 12,

    // Consent defaults
    'require_consent_for_upload' => true,
    'require_consent_for_claim'  => true,
];
