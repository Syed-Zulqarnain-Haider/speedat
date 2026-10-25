<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Str;

/**
 * The price hold: one switch that takes every price off the website at once,
 * without publishing anything. Port of src/lib/site/hold.ts.
 *
 * It lives in `app_settings`, outside the published document, on purpose:
 * turning it on must never push a half-keyed draft, and turning it off must
 * never need a diff. While it is on, the quote page shows the hold message and
 * a WhatsApp form instead of the calculator, and no price reaches the browser.
 * Publishing new rates can lift it (the publisher chooses), as does Resume.
 *
 * Server-side only: every read and write here touches the database.
 */
final class Hold
{
    public const KEY = 'hold';

    /** The `updated_by` column takes 190 characters; a longer sign-in is cut, never truncated by MySQL. */
    private const BY_MAX = 190;

    public static function get(): HoldState
    {
        $raw = Db::value('SELECT v FROM app_settings WHERE k = ?', [self::KEY]);

        return HoldState::fromArray(Db::unjson($raw));
    }

    /**
     * Switch the hold on or off. Returns what is now stored.
     *
     * A null message keeps the message already stored: switching the hold off
     * must not wipe the sentence the owner wrote, because the next time he
     * switches it on he expects the same one back.
     */
    public static function set(bool $on, string $by, ?string $message = null): HoldState
    {
        $current = self::get();
        $who     = Str::limit($by, self::BY_MAX);

        $next = new HoldState(
            on: $on,
            message: $message === null ? $current->message : HoldState::cleanMessage($message),
            by: $who,
            since: Clock::utcNow()->format('Y-m-d\TH:i:s.v\Z'),
        );

        Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :at, :by)'
            . ' ON DUPLICATE KEY UPDATE v = :v2, updated_at = :at2, updated_by = :by2',
            [
                ':k'   => self::KEY,
                ':v'   => Db::json($next->toArray()),
                ':at'  => Clock::sql(),
                ':by'  => $who,
                ':v2'  => Db::json($next->toArray()),
                ':at2' => Clock::sql(),
                ':by2' => $who,
            ]
        );

        // The public site caches the hold with the document; a switch that the
        // customer only sees five minutes later is a switch that did not work.
        Live::bust();

        return $next;
    }
}
