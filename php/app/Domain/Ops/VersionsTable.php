<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Log;
use Speedat\Domain\Ops\Contract\Versions;
use Speedat\Domain\SiteDoc\Repo;

/**
 * Contract\Versions over the REAL version repository, Speedat\Domain\SiteDoc\Repo.
 *
 * It used to run its own
 * `SELECT version, published_at, JSON_EXTRACT(data, '$.live') FROM versions
 *  ORDER BY version DESC LIMIT 1`,
 * because SiteDoc's repository was still being written when the operational
 * surface needed the number. That is over. There are no longer two places that
 * know how the live version is found, so a change to the version schema cannot
 * leave /health and /metrics reading a shape nothing else uses any more.
 *
 * WHAT WAS GIVEN UP, AND WHY THIS IS STILL THE RIGHT WAY ROUND. The hand-written
 * query pulled the `live` flag out inside MySQL and never loaded the document;
 * Repo::getLatestVersion() deserialises the whole site document - every
 * destination, every 1..25 kg box - to answer "how old is this, and is it live".
 * That is a bigger read on each /metrics scrape. One definition of "the live
 * version" is worth more than a saved deserialise on an endpoint that ~50
 * internal users and one uptime monitor call, and the day the document is big
 * enough for it to matter, the fix belongs in Repo where every caller gets it.
 *
 * IT STILL NEVER WRITES, and it still never throws. Publishing is SiteDoc's; an
 * ops endpoint that could change the live version would be a far worse thing
 * than a duplicated read. A database that is down is reported by the health
 * check's own probe, so here it means only "we do not know" - never an error
 * page, and never a driver message on its way out to a caller.
 */
final class VersionsTable implements Versions
{
    public function live(): ?LiveVersion
    {
        try {
            $published = Repo::getLatestVersion();
        } catch (\Throwable $e) {
            Log::exception('ops_live_version_failed', $e);

            return null;
        }

        if ($published === null) {
            return null;
        }

        return new LiveVersion(
            $published->version,
            self::utc($published->publishedAt),
            $published->data->live,
        );
    }

    /**
     * Read the repository's ISO-8601 timestamp as the UTC it is.
     *
     * Repo hands back "2026-09-20T06:30:00.000Z". Core\Clock::fromSql would give
     * the display zone, which is right for a page and wrong for arithmetic - and
     * every age in this package is arithmetic.
     */
    private static function utc(string $iso): ?\DateTimeImmutable
    {
        if (trim($iso) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // A stored timestamp nobody can parse means the age is unknown. A
            // version with an unreadable date is still a published version, so
            // the number survives and only the age reads as null.
            return null;
        }
    }
}
