<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Response;
use Speedat\Domain\Analytics\PageViews;
use Speedat\Domain\Analytics\QuoteStats;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\View\Admin\AnalyticsPage;

/**
 * The owner's numbers, on one screen: how many people arrived, which country
 * and which weight they asked about, which service they picked, and how many
 * of them finished on the WhatsApp button.
 *
 * READ ONLY. There is no POST here, nothing to save, and nothing audited - a
 * page that only counts is not an admin action, and `Http\Admin\InboxController`
 * does not audit reading the inbox either. Everything the owner can change on
 * this screen is in the query string, which is why the whole thing is
 * bookmarkable and why a reload is a reload.
 *
 * TWO TABLES, TWO CALENDARS, ONE RANGE. `page_views.day` is the owner's own day
 * (Asia/Karachi - db/migrations/0003 says why a DATE has to be), and
 * `quotes.created_at` is UTC like every other DATETIME in the schema. Both
 * readers are handed the SAME two local dates and each converts for itself:
 * `PageViews` compares them against the DATE column directly, `QuoteStats`
 * turns them into the UTC instants that local day spans. Doing the conversion
 * in the controller instead would mean one of the two halves of this page was
 * silently five hours out of step with the other.
 *
 * THE NAMES COME FROM `Shipments\NameLookup`, for the reason the inbox and the
 * shipment desk use it: `quotes` stores a `dest_id` and a `service_id`, and a
 * country taken off the rate card still has months of quotes pointing at it.
 * NameLookup reads the live document first and then the most recent published
 * version that still carried the id, so a retired country reads as a country
 * here and not as "gb".
 */
final class AnalyticsController
{
    /**
     * THE DAY LANDINGS STARTED BEING COUNTED.
     *
     * `page_views` shipped on 2026-09-30 and nothing before that date was ever
     * recorded, anywhere. The screen SAYS so, in a sentence, because the
     * alternative is the owner opening a ninety-day range, seeing two months of
     * nothing followed by a week of traffic, and concluding that his website
     * was dead until last Tuesday. It is a constant and not a query so that the
     * sentence is still true and still specific on a fresh install with an
     * empty table.
     *
     * The FIRST DAY A LANDING WAS ACTUALLY COUNTED is a different question and
     * is asked of the data; see firstCountedDay().
     */
    public const COUNTING_FROM = '2026-09-30';

    /** The range the screen opens on: a month, which is how the owner talks about rates. */
    private const DEFAULT_DAYS = 30;

    /**
     * The longest range the boxes will accept, in days.
     *
     * A year and a day, so "the same month last year" is reachable and a typed
     * `from=1970-01-01` is not sixty thousand bars and a grouped scan of every
     * row in both tables. Over the cap the START moves and the screen says it
     * moved - silently returning different dates than the ones in the boxes is
     * how a number gets read as being about a range it is not.
     */
    private const MAX_DAYS = 366;

    /** Countries in the ranking. Twenty is a screenful; the tail is a long tail. */
    private const TOP_COUNTRIES = 20;

    /**
     * Referring sites in the ranking. Ten, because past Google, Facebook,
     * Instagram and the direct row the list is one-off forum links, and the
     * shares are of every landing either way (AnalyticsPage::referrersRanked()).
     */
    private const TOP_REFERRERS = 10;

    public function __construct(
        private readonly PageViews $views = new PageViews(),
        private readonly QuoteStats $quotes = new QuoteStats(),
    ) {
    }

    /** GET /admin/analytics */
    public function page(App $app, AdminUser $admin): Response
    {
        [$from, $to, $notice] = $this->range($app);

        $names = new NameLookup();

        $page = new AnalyticsPage(
            from: $from,
            to: $to,
            landingsByDay: $this->views->byDay($from, $to),
            landingsTotal: $this->views->total($from, $to),
            quotesTotal: $this->quotes->total($from, $to),
            whatsAppPressed: $this->quotes->pressedWhatsApp($from, $to),
            countries: $this->named($this->quotes->byDestination($from, $to, self::TOP_COUNTRIES), $names, NameLookup::DESTINATIONS),
            services: $this->named($this->quotes->byService($from, $to), $names, NameLookup::SERVICES),
            bands: $this->quotes->byWeightBand($from, $to),
            firstCountedDay: $this->firstCountedDay(),
            countingFrom: self::COUNTING_FROM,
            rangeNotice: $notice,
            // Stored on every landing since the counter shipped and, until
            // 2026-10-25, shown nowhere: where people came from, on what kind
            // of screen, and which page they arrived on.
            referrers: $this->views->byReferrer($from, $to, self::TOP_REFERRERS),
            devices: $this->views->byDevice($from, $to),
            pages: $this->views->byPath($from, $to),
        );

        return $app->page('admin/analytics', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'analytics'),
            'theme' => Screen::theme($app),
        ]);
    }

    /**
     * The range the owner asked for: two local dates and, when they were not
     * taken as given, the reason.
     *
     * `from`/`to` win over `days`, so a bookmarked preset link still means "the
     * last thirty days" today while typed dates mean those dates. Anything
     * unparseable falls back to the default range rather than erroring: a
     * mistyped date in a URL should show the owner his month, not a 400.
     *
     * @return array{\DateTimeImmutable,\DateTimeImmutable,string}
     */
    private function range(App $app): array
    {
        $today = $this->today();

        $from = self::date($app->request->query('from', '', 10));
        $to   = self::date($app->request->query('to', '', 10));

        if ($from === null || $to === null) {
            $days = $this->days($app);

            // `$days - 1`, because a range includes both ends: "the last 7
            // days" is today and the six before it, not today and seven before.
            return [$today->sub(new \DateInterval('P' . ($days - 1) . 'D')), $today, ''];
        }

        $notice = '';

        if ($from > $to) {
            [$from, $to] = [$to, $from];
            $notice = 'Those dates were the wrong way round, so they have been swapped.';
        }

        $span = (int) $from->diff($to)->days + 1;

        if ($span > self::MAX_DAYS) {
            $from   = $to->sub(new \DateInterval('P' . (self::MAX_DAYS - 1) . 'D'));
            $notice = 'That range was longer than ' . self::MAX_DAYS . ' days, so it starts at '
                . $from->format('Y-m-d') . ' instead.';
        }

        return [$from, $to, $notice];
    }

    /**
     * The `?days=` shortcut, clamped to something the page can draw.
     *
     * A non-numeric or absent value is the default; 0 and negatives become 1,
     * because "no days" is not a range anybody meant to ask for.
     */
    private function days(App $app): int
    {
        $raw = $app->request->query('days', '', 4);

        if ($raw === '' || preg_match('/^\d{1,4}$/', $raw) !== 1) {
            return self::DEFAULT_DAYS;
        }

        return max(1, min(self::MAX_DAYS, (int) $raw));
    }

    /**
     * The first day the landing counter ever wrote a row, "" when it never has.
     *
     * Asked through `PageViews::byDay()` rather than with a `MIN(day)` of its
     * own: that method already returns the days it wrote, in order, so the
     * first key IS the answer and this screen adds no second statement against
     * a table it does not own. The window starts at COUNTING_FROM, before
     * which there is nothing by definition.
     */
    private function firstCountedDay(): string
    {
        $zone  = Clock::zone();
        $since = new \DateTimeImmutable(self::COUNTING_FROM . ' 00:00:00', $zone);
        $days  = $this->views->byDay($since, $this->today());

        return (string) (array_key_first($days) ?? '');
    }

    /** Today, as a local calendar date at midnight. */
    private function today(): \DateTimeImmutable
    {
        $zone = Clock::zone();

        return new \DateTimeImmutable(Clock::now()->format('Y-m-d') . ' 00:00:00', $zone);
    }

    /**
     * `id => count` becomes the ranked rows the page prints, with the names
     * resolved and the order kept.
     *
     * The order is the QUERY's - busiest first - and is not re-sorted here: the
     * database already decided it, with a tie-break on the id so two countries
     * with the same count do not swap places between two reloads.
     *
     * @param  array<string,int>               $counts
     * @return list<array{label:string,n:int}>
     */
    private function named(array $counts, NameLookup $names, string $field): array
    {
        $out = [];

        foreach ($counts as $id => $n) {
            $out[] = [
                // nameOr() can never return an empty string for a non-empty id,
                // so a row can never print as a blank line. An id no published
                // version ever knew prints as the id, which is a visible
                // question rather than an invisible gap.
                'label' => $id === '' ? "-" : $names->nameOr($field, $id),
                'n'     => $n,
            ];
        }

        return $out;
    }

    /**
     * A `YYYY-MM-DD` from the query string, or null.
     *
     * `checkdate()` as well as the pattern: "2026-02-31" matches the pattern
     * and is not a day, and PHP would roll it forward to 3 March without
     * complaining.
     */
    private static function date(string $raw): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m) !== 1) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($m[0] . ' 00:00:00', Clock::zone());
    }
}
