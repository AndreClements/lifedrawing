<?php

/** Optional isolated database for CLI regression suites; never accepts a live name. */
$testDatabase = getenv('LDR_TEST_DATABASE');
if ($testDatabase !== false && $testDatabase !== '') {
    if (!preg_match('/\Aldr_test_management_[a-f0-9]{8}\z/', $testDatabase)) {
        throw new RuntimeException('LDR_TEST_DATABASE must name an isolated management test database.');
    }
    $_ENV = array_merge($_ENV, [
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $testDatabase,
        'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_ENV' => 'local',
        'APP_BASE_PATH' => '', 'APP_URL' => 'http://127.0.0.1:8765',
        'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '',
        'APP_SITTER_AUTO_COMPLETE' => 'false',
    ]);
}
