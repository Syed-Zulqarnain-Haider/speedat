<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Domain\Ops\Contract\HoldSource;
use Speedat\Domain\Ops\Contract\Versions;

/**
 * Prometheus exposition, computed from the database on every scrape. The port
 * of src/app/api/metrics/route.ts.
 *
 * There is no long-lived PHP process to keep counters in - each request is a
 * fresh interpreter - so every number is a COUNT(*) taken now, through Counts,
 * which is the same class the admin badges read. One query per metric, no
 * counting from a list, no two screens disagreeing.
 *
 * TWO GAUGES THE TYPESCRIPT EXPORTED ARE NOT HERE. They described the Excel
 * rate-sheet feature, which the owner removed from the product on 2026-09-27. A
 * gauge that always reads zero is worse than no gauge: someone eventually writes
 * an alert rule against it and then believes the silence. Every other metric
 * name, its HELP text, its TYPE and its order are exactly as the TypeScript
 * emitted them, so existing dashboards keep working.
 */
final class Metrics
{
    public function __construct(
        private readonly Counts $counts,
        private readonly Versions $versions,
        private readonly HoldSource $hold,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public static function fromConfig(): self
    {
        $now = Clock::utcNow();

        return new self(new Counts($now), new VersionsTable(), new HoldSetting(), $now);
    }

    /**
     * The scrape. 401 without the token, exactly as the TypeScript answered -
     * body and wording included, so an existing check keeps matching.
     *
     * `tokens.metrics` falls back to `tokens.cron`, which is what
     * `METRICS_TOKEN || CRON_SECRET` did: one secret is enough for a site this
     * size, and a deploy that set only the cron one still gets metrics.
     */
    public function respond(?string $authorization): OpsResponse
    {
        $token = Config::str('tokens.metrics');
        if ($token === '') {
            $token = Config::str('tokens.cron');
        }

        if (!BearerToken::matches($token, $authorization)) {
            return OpsResponse::unauthorized('Bad or missing token');
        }

        return OpsResponse::prometheus($this->render());
    }

    /** The exposition text, without the authorisation check. */
    public function render(): string
    {
        $live = $this->versions->live();
        $hold = $this->hold->current();

        $lines = [];

        $this->gauge($lines, 'speedat_prices_held', 'Whether prices are hidden from the website (1) or shown (0)', $hold->on ? 1 : 0);
        $this->gauge($lines, 'speedat_prices_held_seconds', 'How long prices have been on hold, 0 when shown', $hold->seconds($this->now));
        $this->gauge($lines, 'speedat_quotes_24h', 'Quotes logged in the last 24 hours', $this->counts->quotes24h());
        $this->gauge($lines, 'speedat_quotes_booked_24h', 'Quotes where Book was tapped in the last 24 hours', $this->counts->quotesBooked24h());
        $this->gauge($lines, 'speedat_leads_new', 'Leads waiting in the inbox', $this->counts->leadsNew());
        $this->gauge($lines, 'speedat_shipments_open', 'Shipments not yet delivered', $this->counts->shipmentsOpen());
        $this->gauge($lines, 'speedat_shipments_exception', 'Shipments needing attention', $this->counts->shipmentsException());
        $this->gauge($lines, 'speedat_notify_failed_24h', 'Notifications not delivered in the last 24 hours', $this->counts->notifyFailed24h());
        $this->gauge($lines, 'speedat_rates_version', 'Live rates version', $live?->version ?? 0);

        // -1 means "nothing has ever been published", which is a different
        // thing from "published a moment ago" and has to stay tellable apart.
        $this->gauge(
            $lines,
            'speedat_rates_publish_age_seconds',
            'Seconds since the live rates were published',
            $live === null ? -1 : $live->ageSeconds($this->now)
        );

        // The exposition format wants a final newline; a scraper is entitled to
        // reject the last sample without one.
        return implode("\n", [...$lines, '']);
    }

    /** @param list<string> $lines */
    private function gauge(array &$lines, string $name, string $help, int $value): void
    {
        $lines[] = '# HELP ' . $name . ' ' . $help;
        $lines[] = '# TYPE ' . $name . ' gauge';
        $lines[] = $name . ' ' . $value;
    }
}
