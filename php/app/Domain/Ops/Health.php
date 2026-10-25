<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Ops\Contract\HoldSource;
use Speedat\Domain\Ops\Contract\Versions;

/**
 * The health check. A real database round-trip, the live version's age, the
 * price-hold state, the directories the application writes to, and the PHP it
 * is actually running on.
 *
 * The last one is not padding. docs/hostinger-constraints.md §2 records that
 * the PHP version is set per PLAN and not per site, that extensions are
 * toggleable in hPanel, and that `intl`, `sodium` and `zip` may simply not be
 * there. "It works on the laptop" is not evidence about that host, and this is
 * the endpoint that answers the question from the host itself.
 *
 * Two answers, one check:
 *   - without the metrics token, one boolean;
 *   - with it, the detail, which docs/deploy-hostinger.md §12 documents.
 * Both carry the same status code, so an uptime monitor that only ever calls
 * the public form still goes red on a 503.
 */
final class Health
{
    /**
     * Without these the application cannot run at all, so their absence is
     * "down" rather than a note: pdo_mysql is every query, mbstring is every
     * customer name, openssl is the TOTP secrets and the Firebase signatures,
     * json is the site document itself.
     *
     * @var list<string>
     */
    public const REQUIRED = ['json', 'pdo_mysql', 'mbstring', 'openssl'];

    /**
     * Reported because their absence changes behaviour somewhere, but each has
     * a fallback and none of them stops the site serving a price.
     *
     * @var list<string>
     */
    public const OPTIONAL = ['curl', 'iconv', 'intl', 'zip', 'sodium', 'gd', 'fileinfo', 'opcache'];

    public function __construct(
        private readonly Versions $versions,
        private readonly HoldSource $hold,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(new VersionsTable(), new HoldSetting());
    }

    /**
     * Whether the caller may see the detail.
     *
     * Accepts the token from the query string (how the uptime monitor has
     * always called it) or as a bearer header (how everything else does), both
     * compared in constant time. An unconfigured `tokens.metrics` unlocks
     * nothing.
     */
    public static function detailUnlocked(?string $queryToken, ?string $authorization = null): bool
    {
        $configured = Config::str('tokens.metrics');

        return BearerToken::equals($configured, $queryToken)
            || BearerToken::matches($configured, $authorization);
    }

    public function respond(bool $detailed): OpsResponse
    {
        $report = $this->check();

        return OpsResponse::json(
            $detailed ? $report->detailBody() : $report->publicBody(),
            $report->httpStatus()
        );
    }

    public function check(): HealthReport
    {
        $at = Clock::utcNow();

        $started    = microtime(true);
        $databaseUp = Db::healthy();
        $databaseMs = (int) round((microtime(true) - $started) * 1000);

        // Only ask about the document when the database answered. Two more
        // failing queries would add seconds to a check whose whole job is to
        // answer quickly when things are broken.
        $live = $databaseUp ? $this->versions->live() : null;
        $hold = $databaseUp ? $this->hold->current() : Hold::off();

        $extensions = [];
        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $name) {
            $extensions[$name] = self::hasExtension($name);
        }

        $missing = array_values(array_filter(
            self::REQUIRED,
            static fn (string $name): bool => $extensions[$name] === false
        ));

        return new HealthReport(
            $databaseUp,
            $databaseMs,
            $live,
            $hold,
            self::storage(),
            $extensions,
            $missing,
            $at,
            Config::env(),
        );
    }

    /**
     * The directories the application writes to, by NAME - never by path. The
     * server's directory layout is not something a health endpoint gives away.
     *
     * The check is a pure read: is_dir() and is_writable(), no mkdir. A health
     * check that creates what it is checking for always passes.
     *
     * @return array<string,bool>
     */
    private static function storage(): array
    {
        return [
            'logs'  => self::writable(Paths::logs()),
            'cache' => self::writable(Paths::cache()),
            'tmp'   => self::writable(Paths::tmp()),
        ];
    }

    private static function writable(string $dir): bool
    {
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * OPcache is loaded under the name "Zend OPcache", which
     * extension_loaded('opcache') does not match - and a health check that
     * reports the site's opcode cache as missing when it is on is a health
     * check nobody believes the second time.
     */
    private static function hasExtension(string $name): bool
    {
        if ($name === 'opcache') {
            return extension_loaded('Zend OPcache') || extension_loaded('opcache');
        }

        return extension_loaded($name);
    }
}
