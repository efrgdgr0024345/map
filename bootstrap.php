<?php
declare(strict_types=1);

date_default_timezone_set('Australia/Perth');
define('APP_ROOT', __DIR__);
define('APP_VAR', __DIR__ . '/var');
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/src/' . substr($class, 4) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
