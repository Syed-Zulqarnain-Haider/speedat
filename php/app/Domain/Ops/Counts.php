<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Db;

/**
 * THE place these numbers are counted. The admin badges, the metrics endpoint
 * and the cron job all ask this class, so they cannot disagree.
 *
 * They used to. In the Next.js admin one badge counted a number by taking the
 * first fifteen rows and counting them, the cron took twenty, and the metrics
 * endpoint counted in SQL - three numbers for one question, and the badge was
 * simply wrong once there were more than fifteen rows. Every count here is
 * therefore ONE aggregate query against the whole table: `COUNT(*)` does not
 * have a page size, and nothing downstream can accidentally count a list that
 * was only ever the first screenful.
 *
 * Results are memoised per instance, so building the whole metrics page costs
 * one query per number and not one per reader. Make a new instance to ask
 * again - these are cheap, and a long-lived one would go stale silently.
 *
 * The clock is injected and is always UTC: `created_at` is UTC in the
 * database, so a 24-hour window measured in Asia/Karachi would be the wrong
 * twenty-four hours by five.
 */
final class Counts
{
    /** @var array<string,int> */
    private array $memo = [];

    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public static function now(): self
    {
        return new self(Clock::utcNow());
    }

    /** The start of the rolling 24-hour window, as a SQL DATETIME in UTC. */
    public function since(): string
    {
        return Clock::sql($this->now->sub(new \DateInterval('PT24H')));
    }

    public function quotes24h(): int
    {
        return $this->count('quotes24h', 'SELECT COUNT(*) FROM quotes WHERE created_at >= ?', [$this->since()]);
    }

    public function quotesBooked24h(): int
    {
        return $this->count(
            'booked24h',
            'SELECT COUNT(*) FROM quotes WHERE created_at >= ? AND booked = 1',
            [$this->since()]
        );
    }

    /** Leads waiting in the inbox - the number on the admin's inbox badge. */
    public function leadsNew(): int
    {
        return $this->count('leadsNew', "SELECT COUNT(*) FROM leads WHERE status = 'new'");
    }

    public function shipmentsOpen(): int
    {
        return $this->count('shipmentsOpen', "SELECT COUNT(*) FROM shipments WHERE status <> 'delivered'");
    }

    public function shipmentsException(): int
    {
        return $this->count('shipmentsException', "SELECT COUNT(*) FROM shipments WHERE status = 'exception'");
    }

    /**
     * Notifications that did not reach anybody in the last 24 hours.
     *
     * Both outcomes count. `notify_failed` is a transport that refused;
     * `notify_skipped` is mail that was never configured - and a site that has
     * quietly never been able to email the office is not healthier than one
     * whose mail server is down, it is just quieter about it.
     */
    public function notifyFailed24h(): int
    {
        return $this->count(
            'notifyFailed24h',
            "SELECT COUNT(*) FROM audit_log
               WHERE at >= ?
                 AND action IN ('notify_failed', 'notify_skipped', 'mail_failed', 'mail_skipped')",
            [$this->since()]
        );
    }

    /**
     * Every number at once, for a caller that needs the lot.
     *
     * @return array{quotes24h:int,quotesBooked24h:int,leadsNew:int,shipmentsOpen:int,shipmentsException:int,notifyFailed24h:int}
     */
    public function all(): array
    {
        return [
            'quotes24h'          => $this->quotes24h(),
            'quotesBooked24h'    => $this->quotesBooked24h(),
            'leadsNew'           => $this->leadsNew(),
            'shipmentsOpen'      => $this->shipmentsOpen(),
            'shipmentsException' => $this->shipmentsException(),
            'notifyFailed24h'    => $this->notifyFailed24h(),
        ];
    }

    /** @param list<string> $params */
    private function count(string $memoKey, string $sql, array $params = []): int
    {
        return $this->memo[$memoKey] ??= (int) Db::value($sql, $params, 0);
    }
}
