<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Where everything lives on disk.
 *
 * The layout is the security model: `public/` is the only directory a web
 * server ever points at, and every other directory is its sibling, one level
 * up. Nothing below resolves a path from user input.
 *
 *   php/
 *     public/     <- becomes public_html on Hostinger
 *     app/
 *     config/
 *     storage/    cache/ logs/ tmp/ uploads/
 *     db/migrations/
 *     var/
 */
final class Paths
{
    private static string $root = '';

    /** @param string $root Absolute path to the php/ directory. */
    public static function init(string $root): void
    {
        self::$root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function root(): string
    {
        if (self::$root === '') {
            throw new \RuntimeException('Paths::init() was never called.');
        }

        return self::$root;
    }

    public static function app(): string
    {
        return self::root() . '/app';
    }

    public static function config(): string
    {
        return self::root() . '/config';
    }

    public static function configFile(): string
    {
        return self::config() . '/config.php';
    }

    public static function publicDir(): string
    {
        return self::root() . '/public';
    }

    public static function views(): string
    {
        return self::app() . '/Views';
    }

    public static function migrations(): string
    {
        return self::root() . '/db/migrations';
    }

    public static function storage(): string
    {
        return self::configured('paths.storage', self::root() . '/storage');
    }

    public static function cache(): string
    {
        return self::configured('paths.cache', self::storage() . '/cache');
    }

    public static function logs(): string
    {
        return self::configured('paths.logs', self::storage() . '/logs');
    }

    public static function tmp(): string
    {
        return self::configured('paths.tmp', self::storage() . '/tmp');
    }

    public static function uploads(): string
    {
        return self::configured('paths.uploads', self::storage() . '/uploads');
    }

    /**
     * Create a writable directory, or say precisely which one could not be
     * created. A silently unwritable log directory is how an incident becomes
     * invisible.
     */
    public static function ensureWritable(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }

    /**
     * Directory names that mean "the web server serves this". Hostinger uses
     * `public_html`; `public` is this project's own name for it; `htdocs` and
     * `www` are what other panels call it, and a tree copied from one to the
     * other keeps the old name.
     */
    private const WEB_ROOT_NAMES = ['public', 'public_html', 'htdocs', 'www'];

    /**
     * An overridable path from config, refused if it points into the web root.
     *
     * WHY THE CHECK IS HERE. This class's own docblock says "the layout is the
     * security model": `public/` is the only directory a web server points at
     * and everything else is its sibling. Five values could be overridden from
     * config with nothing checking them, so `paths.logs => 'public/logs'` - a
     * typo, or a copy-paste from a framework that keeps storage under the web
     * root - published the structured log, which carries request paths, hashed
     * IPs, user agents and driver messages. `storage/.htaccess` does not travel
     * with a path somebody redirected; and a writable directory under the web
     * root is one upload bug away from being a web shell.
     *
     * IT THROWS RATHER THAN FALLING BACK. A silent correction would mean the
     * operator's configuration and the running application disagree about where
     * the logs are, and the first person to notice would be whoever went looking
     * for a log that was never there. ConfigError is the same failure the rest of
     * Config uses for a value that cannot be used, and Bootstrap renders it as
     * "this site is not configured yet" rather than as a stack trace.
     *
     * The comparison is per SEGMENT, not str_contains: a customer whose account
     * is at /home/u1/public_html/../storage would otherwise be refused for the
     * ".." it needs, and a directory legitimately called "publications" would be
     * refused for starting with the right nine letters.
     *
     * @throws ConfigError when the configured path has a web-root segment in it
     */
    private static function configured(string $key, string $fallback): string
    {
        if (!Config::loaded()) {
            return $fallback;
        }

        $v = Config::str($key);
        if ($v === '') {
            return $fallback;
        }

        $path = rtrim(str_replace('\\', '/', $v), '/');

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (in_array(strtolower($segment), self::WEB_ROOT_NAMES, true)) {
                throw new ConfigError(
                    'Configuration value "' . $key . '" must not point inside the web root: '
                    . 'the segment "' . $segment . '" is a directory the web server publishes. '
                    . 'Put writable directories beside public_html, not inside it.'
                );
            }
        }

        return $path;
    }
}
