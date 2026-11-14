<?php

/**
 * Class loader for installs without Composer.
 *
 * Maps IndianOpinion\SrcsetTruth\Foo\Bar to src/Foo/Bar.php.
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'IndianOpinion\\SrcsetTruth\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (str_starts_with($relative, 'Tests\\')) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
);

require_once __DIR__ . '/functions.php';
