<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('AI Audiobook Studio requires PHP 8.1 or newer.');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_STORAGE', APP_ROOT . '/storage');
define('APP_START', microtime(true));

require APP_ROOT . '/app/Core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing application configuration.');
}
$GLOBALS['app_config'] = require $configFile;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ai_audiobook_session');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
