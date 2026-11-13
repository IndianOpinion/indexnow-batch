<?php

/**
 * Autoloader for installs without Composer.
 *
 * Maps the IndianOpinion\IndexNowBatch namespace onto this directory, PSR-4 style.
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'IndianOpinion\\IndexNowBatch\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
);
