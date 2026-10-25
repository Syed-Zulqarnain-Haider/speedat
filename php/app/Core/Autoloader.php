<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * The whole autoloader. No Composer, no vendor directory, no generated map.
 *
 * PSR-4 in the only form this project needs: one namespace prefix mapped to
 * one directory. `Speedat\Core\Config` becomes `app/Core/Config.php`,
 * `Speedat\Domain\Pricing\Engine` becomes `app/Domain/Pricing/Engine.php`.
 *
 * It refuses any class name containing a character that is not legal in a PHP
 * identifier, so a crafted string can never be turned into a file path.
 */
final class Autoloader
{
    /** @var array<string,string> prefix (with trailing backslash) => absolute base directory */
    private static array $prefixes = [];

    private static bool $registered = false;

    /**
     * Map a namespace prefix onto a directory and start autoloading.
     *
     * @param string $baseDir Absolute path, no trailing separator required.
     */
    public static function register(string $baseDir, string $prefix = 'Speedat\\'): void
    {
        $prefix = rtrim($prefix, '\\') . '\\';
        self::$prefixes[$prefix] = rtrim(str_replace('\\', '/', $baseDir), '/');

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load'], true, false);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        // Only ASCII identifiers separated by backslashes. Anything else is not
        // a class this project defines, and is not worth touching the disk for.
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $class) !== 1) {
            return;
        }

        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
