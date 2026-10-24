<?php

declare(strict_types=1);

/**
 * Lightweight PSR-4-style runtime autoloader for the local xCurl package.
 *
 * Runtime usage (Composer is not required):
 *     require_once $baseDir . '/lib/xCurl/autoload.php';
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'AbsolutOffice\\XCurl\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '') {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
