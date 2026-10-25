<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Configuration: one PHP file, above the web root, returning an array.
 *
 * Why not .env: a .env file is served as plain text by any web server that
 * does not know to hide it. A .php file is executed, so the worst case of a
 * misconfigured host is a blank page, not a published password.
 *
 * Values are read by dot path - Config::str('db.host'). `need()` is the one
 * that fails loudly: it throws when a value is missing or empty, naming the
 * key and never the value.
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    private static string $file = '';

    /**
     * Load config/config.php (or whichever file is passed).
     *
     * Fails loudly and specifically: the operator needs to know whether the
     * file is missing, unreadable or malformed, and must not have to read
     * source code to find out.
     */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new ConfigError(
                'Configuration file not found: ' . self::displayPath($file) . '. '
                . 'Copy config/config.sample.php to config/config.php and fill it in.'
            );
        }

        if (!is_readable($file)) {
            throw new ConfigError(
                'Configuration file is not readable: ' . self::displayPath($file) . '. '
                . 'Check its permissions (600, owned by the PHP user).'
            );
        }

        /** @psalm-suppress UnresolvableInclude */
        $data = require $file;

        if (!is_array($data)) {
            throw new ConfigError(
                'Configuration file must `return [...];` an array: ' . self::displayPath($file) . '.'
            );
        }

        /** @var array<string,mixed> $data */
        self::$data = $data;
        self::$file = $file;
    }

    public static function loaded(): bool
    {
        return self::$data !== null;
    }

    /** Where the loaded configuration came from. Used in diagnostics, never printed to visitors. */
    public static function file(): string
    {
        return self::$file;
    }

    /** Forget everything. Tests only. */
    public static function reset(): void
    {
        self::$data = null;
        self::$file = '';
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new ConfigError('Configuration has not been loaded yet (Config::load was never called).');
        }

        $node = self::$data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public static function str(string $path, string $default = ''): string
    {
        $v = self::get($path, $default);

        return is_scalar($v) ? (string) $v : $default;
    }

    public static function int(string $path, int $default = 0): int
    {
        $v = self::get($path, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $path, bool $default = false): bool
    {
        $v = self::get($path, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }

        return is_numeric($v) ? ((int) $v) === 1 : $default;
    }

    /** @return array<array-key,mixed> */
    public static function arr(string $path): array
    {
        $v = self::get($path, []);

        return is_array($v) ? $v : [];
    }

    /**
     * A value the application cannot run without.
     *
     * @throws ConfigError when missing or empty - naming the key, never the value.
     */
    public static function need(string $path): string
    {
        $v = self::get($path);
        if (!is_scalar($v) || (string) $v === '') {
            throw new ConfigError(
                'Required configuration value "' . $path . '" is missing or empty in '
                . self::displayPath(self::$file !== '' ? self::$file : 'config/config.php') . '.'
            );
        }

        return (string) $v;
    }

    /**
     * Override a value in memory. For the CLI tools and the test runner only -
     * nothing in a web request may call this, because configuration that can
     * be changed at runtime is configuration nobody can audit.
     */
    public static function set(string $path, mixed $value): void
    {
        if (self::$data === null) {
            self::$data = [];
        }

        $segments = explode('.', $path);
        $node     = &self::$data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $node[$segment] = $value;
                break;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        unset($node);
    }

    public static function env(): string
    {
        $env = strtolower(self::str('app.env', 'production'));

        return $env !== '' ? $env : 'production';
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    /**
     * Debug output is allowed only when explicitly enabled AND the environment
     * is not production. Two conditions, because a config file copied from the
     * laptop to the server is a thing that happens.
     */
    public static function isDebug(): bool
    {
        return !self::isProduction() && self::bool('app.debug', false);
    }

    /**
     * The 32-byte application key, decoded.
     *
     * Used to derive every other secret (CSRF signing, IP hashing, TOTP
     * encryption) so there is exactly one secret to rotate and exactly one to
     * lose.
     */
    public static function appKey(): string
    {
        $raw = self::need('app.key');
        $key = base64_decode($raw, true);

        if ($key === false || strlen($key) < 32) {
            throw new ConfigError(
                'Configuration value "app.key" must be 32 random bytes, base64-encoded. '
                . 'Generate one with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        return $key;
    }

    /**
     * A purpose-bound key derived from the application key. Deriving rather
     * than reusing means a leak of one subsystem's key does not hand over the
     * others.
     */
    public static function derivedKey(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, self::appKey(), true);
    }

    /** Show a path without leaking the server's directory layout to visitors. */
    private static function displayPath(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
