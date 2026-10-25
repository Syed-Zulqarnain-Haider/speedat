<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Log;
use Speedat\Domain\Ops\Contract\HoldSource;
use Speedat\Domain\SiteDoc\Hold as SiteDocHold;

/**
 * Contract\HoldSource over the REAL hold repository, Speedat\Domain\SiteDoc\Hold.
 *
 * It used to read the `hold` row of app_settings itself and pick `on`, `by` and
 * `since` out of the JSON by hand, because SiteDoc's hold was still being
 * written when the operational surface needed the state. Two readers of one row
 * is two answers to "are prices showing" waiting to disagree, so there is one
 * now: SiteDoc\Hold::get() reads the row and SiteDoc\HoldState decides what a
 * damaged one means. This class only narrows the result to the three facts Ops
 * reports.
 *
 * WHAT IS DROPPED ON THE WAY THROUGH. HoldState also carries the message
 * customers read. Ops reports the STATE, never the copy: /health and /metrics
 * are not places to publish a sentence the owner wrote for the shop front.
 *
 * READ-ONLY, deliberately. Ops alerts on a hold that has been left on and must
 * never be able to switch it. Only an admin does that, through Domain\SiteDoc,
 * with an audit row against their name.
 *
 * STILL FORGIVING. A hold row hand-edited into nonsense reads as "no hold" and
 * lets the site keep quoting - HoldState::fromArray is where that decision lives
 * now, and it makes it for the shop front and for this endpoint identically. A
 * database that cannot be reached gives the same "prices are showing" answer
 * plus a log line, because a page that was only asking whether prices are
 * visible must not become an error page.
 *
 * NOTE FOR TESTS. There is no injectable row key any more; the hold has exactly
 * one row and SiteDoc owns its name. A test that needs a particular hold writes
 * the real row and puts it back afterwards, which is also the only way to prove
 * this reads what the shop front reads.
 */
final class HoldSetting implements HoldSource
{
    public function current(): Hold
    {
        try {
            $state = SiteDocHold::get();
        } catch (\Throwable $e) {
            Log::exception('ops_hold_read_failed', $e);

            return Hold::off();
        }

        return new Hold($state->on, self::instant($state->since), $state->by);
    }

    /**
     * HoldState keeps `since` as the ISO-8601 string whoever set the hold wrote.
     * Anything unparseable becomes null: a hold whose start is unknown is still
     * a hold, and its age reads as zero rather than as a crash.
     */
    private static function instant(?string $since): ?\DateTimeImmutable
    {
        if ($since === null || trim($since) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
