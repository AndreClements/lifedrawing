<?php
declare(strict_types=1);

// Local browser fixtures only: php -S 127.0.0.1:8765 -t public tools/test-management-router.php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('LDR_ROOT', dirname(__DIR__));
$state = json_decode(file_get_contents(LDR_ROOT . '/storage/cache/management-test.json'), true);
putenv('LDR_TEST_DATABASE=' . ($state['database'] ?? 'invalid'));
require __DIR__ . '/local-test-environment.php';
// Separate rate-limit files from ordinary local site use.
$_SERVER['REMOTE_ADDR'] = '127.0.0.199';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/') && is_file(LDR_ROOT . '/public' . $path)) return false;
require LDR_ROOT . '/vendor/autoload.php';
(new App\Kernel())->handle(App\Request::capture())->send();
