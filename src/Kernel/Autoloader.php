<?php
namespace Mailpro\Kernel;

/**
 * PSR-4 Compliant Native Autoloader
 * Automatically maps Mailpro\ namespace to the src/ directory.
 * No Composer required; zero-dependency native PHP execution.
 */
class Autoloader
{
    private static bool $registered = false;
    private static string $baseDir = '';

    public static function register(?string $baseDir = null): void
    {
        if (self::$registered) {
            return;
        }

        self::$baseDir = $baseDir ?? dirname(__DIR__); // points to src/
        self::$registered = true;

        spl_autoload_register(function (string $class) {
            $prefix = 'Mailpro\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = self::$baseDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}
