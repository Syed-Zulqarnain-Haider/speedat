<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Audit;
use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Domain\Notify\Alerts;
use Speedat\Domain\Notify\Notifier;
use Speedat\Domain\Notify\OfficeMessages;
use Speedat\Domain\Ops\Contract\HoldSource;
use Speedat\Domain\Ops\Contract\Versions;

/**
 * The periodic job. The port of src/app/api/cron/check-sheets/route.ts, minus
 * the two checks that were about an emailed rate sheet.
 *
 * WHAT IT CHECKS
 *   1. rates older than the staleness threshold;
 *   2. a price hold nobody has lifted.
 * Each raises a deduplicated alert at most once a day.
 *
 * WHERE THE ALERTS GO. Through Speedat\Domain\Notify\Alerts, which is the only
 * alerts implementation and the only sender: it owns the once-a-day claim, the
 * `[Speedat] ` subject prefix and the SMTP transport. This package used to carry
 * its own port of alerts.ts (Ops\Alerts, over a Contract\Notifier bound to
 * Core\Mail) that wrote the SAME app_settings row with the same markers -
 * whichever of the two ran first silenced the other for the day. It is gone, and
 * so is the stand-in interface: this file names the real class.
 *
 * THE WORDS ARE NOT HERE EITHER. Notify\OfficeMessages holds both alert texts,
 * because it already held them - it is the port of the same two messages out of
 * check-sheets/route.ts - and two copies of a message the office recognises by
 * its shape is one copy that quietly drifts. What this class keeps is the clock:
 * it converts every stored UTC instant into the zone the owner's phone shows
 * before handing it over (see display()).
 *
 * WHAT IT NO LONGER CHECKS. The TypeScript also alerted when nothing had
 * arrived at the intake mailbox by the expected hour, and when files were
 * waiting for a human. The owner removed the Excel rate-sheet feature from the
 * product on 2026-09-27; there is no mailbox to be silent and no queue to wait
 * in, so both checks are gone rather than rewritten.
 *
 * WHAT IT ALSO DOES. Housekeeping the host needs and cannot do for itself -
 * expired sessions, stale rate-limit windows, an ageing cache directory, and
 * one line a day recording which PHP the CLI actually runs (see Housekeeping
 * for why each of those is here) - and it records the day's numbers through
 * Counts, the same helper the admin badges and the metrics scrape read.
 *
 * EVERY JOB IS WRAPPED. One that throws is recorded and the rest still run: a
 * cron that stops at the first error is a cron whose later jobs silently never
 * happen. A failure anywhere makes the whole run non-zero, because the exit
 * status is the only part of this anybody reads.
 */
final class Cron
{
    /** The app_settings row holding the cron's own once-a-day markers. */
    public const SETTINGS_KEY = 'ops';

    /** Matches RATES_STALE_DAYS in the TypeScript. */
    private const DEFAULT_STALE_DAYS = 7;

    /** Matches HOLD_ALERT_HOURS: longer than a working day means nobody is coming back to it. */
    private const DEFAULT_HOLD_HOURS = 12;

    public function __construct(
        private readonly Alerts $alerts,
        private readonly Counts $counts,
        private readonly Versions $versions,
        private readonly HoldSource $hold,
        private readonly Housekeeping $housekeeping,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public static function fromConfig(?\DateTimeImmutable $now = null): self
    {
        $at = $now ?? Clock::utcNow();

        return new self(
            new Alerts(Notifier::fromConfig()),
            new Counts($at),
            new VersionsTable(),
            new HoldSetting(),
            Housekeeping::fromConfig($at),
            $at,
        );
    }

    /**
     * The HTTP door, for a plan whose cron can only fetch a URL.
     *
     * The wording of the 401 is the TypeScript's, verbatim. An unset
     * `tokens.cron` refuses everybody - a cron endpoint that opens itself when
     * it is misconfigured is a button anyone on the internet may press.
     */
    public function respond(?string $authorization): OpsResponse
    {
        if (!BearerToken::matches(Config::str('tokens.cron'), $authorization)) {
            return OpsResponse::unauthorized('Bad or missing cron secret');
        }

        return $this->run()->response();
    }

    public function run(): CronResult
    {
        $started = microtime(true);

        /** @var array<string,\Closure():array{string,array<string,mixed>}> $work */
        $work = [
            'rates'       => fn (): array => $this->staleRates(),
            'hold'        => fn (): array => $this->longHold(),
            'counts'      => fn (): array => $this->dailyNumbers(),
            'sessions'    => fn (): array => [$this->housekeeping->expiredSessions() . ' expired session(s) removed', []],
            'rate_limits' => fn (): array => [$this->housekeeping->rateLimitWindows() . ' old window(s) removed', []],
            'cache'       => fn (): array => [$this->housekeeping->cacheFiles() . ' cache file(s) removed', []],
            'environment' => fn (): array => [$this->housekeeping->logEnvironment() ? 'logged' : 'already logged today', []],
        ];

        $checks   = [];
        $jobs     = [];
        $failures = [];

        foreach ($work as $name => $job) {
            try {
                [$summary, $fields] = $job();
                $jobs[$name]        = $summary;
                $checks             = array_merge($checks, $fields);
            } catch (\Throwable $e) {
                // The reason goes to the log in full; the cron log gets the
                // short form, with no stack trace and no query in it.
                Log::exception('cron_job_failed', $e, ['job' => $name]);
                $failures[] = $name . ': ' . $e->getMessage();
            }
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        Log::info('cron_run', ['jobs' => $jobs, 'ms' => $elapsedMs, 'failures' => count($failures)]);
        Audit::log('cron', 'cron_run', ['jobs' => $jobs, 'ms' => $elapsedMs, 'failures' => $failures]);

        return new CronResult($checks, $jobs, $failures, $elapsedMs);
    }

    /**
     * Rates nobody has republished. The site is still quoting from them, which
     * is the whole reason this is worth an email.
     *
     * @return array{string,array<string,mixed>}
     */
    private function staleRates(): array
    {
        $threshold = max(1, Config::int('ops.rates_stale_days', self::DEFAULT_STALE_DAYS));
        $live      = $this->versions->live();

        if ($live === null) {
            return ['nothing published yet', ['ratesAgeDays' => null]];
        }

        $ageDays = $live->ageDays($this->now);
        $fields  = ['ratesAgeDays' => $ageDays];

        if ($ageDays < $threshold) {
            return [$ageDays . ' day(s) since publish', $fields];
        }

        Audit::log('cron', 'rates_stale', ['ageDays' => $ageDays, 'version' => $live->version]);

        $sent = $this->alerts->onceMessage(
            OfficeMessages::ratesStale(
                $ageDays,
                $live->version,
                $this->display($live->publishedAt, 'Y-m-d'),
                $this->baseUrl(),
            ),
            $this->now,
        );

        $fields['staleAlert'] = $sent;

        return [$sent ? 'stale, office told' : 'stale, already alerted today', $fields];
    }

    /**
     * A hold nobody lifted: the site is taking no quotes while it lasts.
     *
     * @return array{string,array<string,mixed>}
     */
    private function longHold(): array
    {
        $threshold = max(1, Config::int('ops.hold_alert_hours', self::DEFAULT_HOLD_HOURS));
        $hold      = $this->hold->current();

        if (!$hold->on) {
            return ['prices are showing', ['holdHours' => null]];
        }

        $hours  = $hold->hours($this->now);
        $fields = ['holdHours' => $hours];

        if ($hours < $threshold) {
            return ['held for ' . $hours . ' hour(s)', $fields];
        }

        Audit::log('cron', 'hold_long', ['hours' => $hours, 'by' => $hold->by]);

        $sent = $this->alerts->onceMessage(
            OfficeMessages::holdLong(
                $hours,
                $hold->by,
                $this->display($hold->since, 'Y-m-d H:i'),
                $this->baseUrl(),
            ),
            $this->now,
        );

        $fields['holdAlert'] = $sent;

        return [$sent ? 'held too long, office told' : 'held too long, already alerted today', $fields];
    }

    /**
     * The day's numbers, counted by the SAME helper the admin badges and the
     * metrics scrape use. Nothing here alerts; it is in the run for two
     * reasons.
     *
     * One: the cron log then carries what the site looked like at that hour.
     * Nobody is guaranteed to be scraping a shared-hosting site with
     * Prometheus, so this log is in practice the only history these numbers
     * have.
     *
     * Two: it exercises the counting. Every other check in this run survives a
     * database that is refusing connections - VersionsTable and HoldSetting
     * both catch and report "unknown", which is right for a page and wrong for
     * a cron, because the run would then exit 0 and nobody would be told. This
     * job does not catch, so an unreachable database - or a column renamed
     * under Counts - makes the run non-zero and puts the reason in the cron
     * log, instead of leaving a badge quietly wrong for a week.
     *
     * @return array{string,array<string,mixed>}
     */
    private function dailyNumbers(): array
    {
        $numbers = $this->counts->all();

        return [
            $numbers['leadsNew'] . ' lead(s) waiting, '
            . $numbers['shipmentsOpen'] . ' shipment(s) open, '
            . $numbers['quotes24h'] . ' quote(s) in 24h',
            $numbers,
        ];
    }

    /**
     * A stored instant, written the way the office reads a clock.
     *
     * Everything internal is UTC; this is the one place that converts, because
     * a mail telling the owner prices went off "at 07:00" when his phone said
     * noon is a mail that costs a phone call.
     */
    private function display(?\DateTimeImmutable $at, string $format): string
    {
        return $at === null ? 'an unknown time' : $at->setTimezone(Clock::zone())->format($format);
    }

    /**
     * The site's own address, with no trailing slash and no path.
     *
     * OfficeMessages appends "/admin" itself, because the message it is writing
     * says where to go and the link belongs with the sentence. This hands it the
     * base only, so there is no way for the two to disagree about the slash.
     */
    private function baseUrl(): string
    {
        return rtrim(Config::str('app.url'), '/');
    }
}
