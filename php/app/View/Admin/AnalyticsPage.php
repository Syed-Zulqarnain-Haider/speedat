<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Clock;
use Speedat\Domain\Analytics\PageViews;
use Speedat\Domain\Analytics\QuoteStats;
use Speedat\Domain\Pricing\Format;

/**
 * The analytics screen, whole: how many people arrived, where they wanted to
 * send something, how heavy it was, which service they picked, and how many of
 * them finished on WhatsApp.
 *
 * EVERY NUMBER ON THIS PAGE CAN BE WRONG IN ONE OF TWO WAYS, and the whole
 * shape of this class is about the second one:
 *
 *   1. It can be MISCOUNTED, which is the queries' problem
 *      (Domain\Analytics\QuoteStats, Domain\Analytics\PageViews) and is what
 *      the tests over those two check.
 *   2. It can be counted correctly and READ as something it is not. A zero for
 *      the first week of the range is the honest count of a week nobody was
 *      counting; a bar chart of thirty days where twelve have no row at all
 *      looks like twelve days with no visitors; a "0%" beside "pressed Book"
 *      in a week with no quotes at all is a measurement of nothing presented as
 *      a measurement of failure.
 *
 * So nothing here returns a bare number. `landings()`, `quotes()` and
 * `whatsApp()` each answer with the number AND with whether it means anything,
 * `bars()` fills the days the counter has no row for so the chart cannot
 * pretend they were quiet, and `countingNote()` says in words that landings
 * began on the day the counter shipped. The template prints; it decides
 * nothing.
 *
 * THE LABELS ARE THIS FILE'S AND NOT THE DOMAIN'S. QuoteStats hands over
 * `dest_id`, `service_id` and band keys; the controller resolves the two ids
 * through `Shipments\NameLookup` - which still knows what a country retired
 * from the rate card was called - and the weight bands are named here, in the
 * words the quote tool puts on its buttons.
 */
final class AnalyticsPage
{
    /** The tallest bar in the landings chart, as a percentage of the chart's height. */
    private const BAR_MAX_PCT = 100;

    /**
     * Above this many days in the range, the chart labels only the ends and
     * the peak.
     *
     * Thirty-one bars at 375px is 8px each and a label under every one is a
     * grey smear; the numbers are all in the table under the chart either way.
     */
    private const LABEL_EVERY_DAY_UP_TO = 14;

    /**
     * @param array<string,int>                 $landingsByDay "Y-m-d" => views, gaps and all
     * @param list<array{label:string,n:int}>   $countries     ranked, names already resolved
     * @param list<array{label:string,n:int}>   $services      ranked, names already resolved
     * @param array<string,int>                 $bands         QuoteStats band key => quotes
     * @param array<string,int>                 $referrers     PageViews::byReferrer(), "" for direct, capped
     * @param array<string,int>                 $devices       PageViews::byDevice()
     * @param array<string,int>                 $pages         PageViews::byPath()
     */
    public function __construct(
        /** The range the owner is looking at, in the site's own time zone. */
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly array $landingsByDay,
        public readonly int $landingsTotal,
        public readonly int $quotesTotal,
        public readonly int $whatsAppPressed,
        public readonly array $countries,
        public readonly array $services,
        public readonly array $bands,
        /**
         * The first day the landing counter ever wrote a row, "" when it never
         * has. Not the same question as the start of the range, and the reason
         * the note under the chart can be specific instead of apologetic.
         */
        public readonly string $firstCountedDay = '',
        /** The day the counter shipped; see Http\Admin\AnalyticsController::COUNTING_FROM. */
        public readonly string $countingFrom = '',
        /** Set when the dates asked for were adjusted, and why. "" when they were taken as given. */
        public readonly string $rangeNotice = '',
        /*
         * Where landings came from, on what screen, and on which page
         * (2026-10-25). The counter has stored all three on every row since
         * the day it shipped and nothing ever showed them: the owner could not
         * see whether people were arriving from WhatsApp, Facebook or Google,
         * or on phones, without phpMyAdmin. Raw `label => views`, labelled
         * here and not in the domain, for the reason byReferrer() gives.
         */
        public readonly array $referrers = [],
        public readonly array $devices = [],
        public readonly array $pages = [],
    ) {
    }

    public function eyebrow(): string
    {
        return "05 - Analytics";
    }

    /* ------------------------------------------------------------------ *
     * The range
     * ------------------------------------------------------------------ */

    /** How many days the range covers, both ends included. */
    public function dayCount(): int
    {
        return count($this->days());
    }

    /** "1 Sept 2026 to 30 Sept 2026 &middot; 30 days". */
    public function rangeLabel(): string
    {
        $days = $this->dayCount();

        return Format::fmtDate($this->from->format('Y-m-d'))
            . ' to ' . Format::fmtDate($this->to->format('Y-m-d'))
            . " \u{b7} " . $days . ' day' . ($days === 1 ? '' : 's');
    }

    /** The value of the two `<input type="date">` boxes. */
    public function fromInput(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toInput(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * The three shortcuts above the boxes, with the current one marked.
     *
     * Active is decided by the LENGTH of the range and by its ending today,
     * not by the query string: an owner who typed the same thirty days into the
     * boxes by hand is looking at "Last 30 days" and the button that says so
     * should agree with the screen.
     *
     * @return list<array{label:string,href:string,active:bool}>
     */
    public function presets(): array
    {
        $out = [];

        foreach (Routes::ANALYTICS_PRESETS as $days) {
            $out[] = [
                'label'  => 'Last ' . $days . ' days',
                'href'   => Routes::analytics($days),
                'active' => $this->endsToday() && $this->dayCount() === $days,
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ *
     * Landings
     * ------------------------------------------------------------------ */

    /**
     * The landings figure and whether it is a measurement.
     *
     * @return array{value:int,days:int,has:bool}
     */
    public function landings(): array
    {
        return [
            'value' => $this->landingsTotal,
            'days'  => count(array_filter($this->landingsByDay, static fn (int $n): bool => $n > 0)),
            'has'   => $this->landingsTotal > 0,
        ];
    }

    /** "12 of the 30 days had a visit" - the note under the landings figure. */
    public function landingsNote(): string
    {
        $landings = $this->landings();

        if (!$landings['has']) {
            return 'nothing counted in these dates';
        }

        return $landings['days'] . ' of the ' . $this->dayCount() . ' days had a visit';
    }

    /**
     * One bar per day in the range, oldest first - INCLUDING the days the
     * counter has no row for.
     *
     * `PageViews::byDay()` returns only the days it wrote, and says so: a day
     * with no traffic is absent rather than zero. Absent is right for the query
     * and wrong for a chart, where thirty bars must mean thirty days or the
     * spacing lies about which day is which. The zeros are put back here.
     *
     * `pct` is of the BUSIEST day, not of the total, because the question a
     * trend answers is "which days were busier than which" - and a percentage
     * of the total would draw thirty bars of 3% on a flat month.
     *
     * @return list<array{day:string,label:string,short:string,views:int,pct:int,peak:bool,labelled:bool}>
     */
    public function bars(): array
    {
        $days  = $this->days();
        $peak  = $this->peakViews();
        $every = count($days) <= self::LABEL_EVERY_DAY_UP_TO;
        $last  = count($days) - 1;

        $out = [];
        foreach ($days as $i => $day) {
            $views = $this->landingsByDay[$day] ?? 0;
            $isTop = $peak > 0 && $views === $peak;

            $out[] = [
                'day'      => $day,
                'label'    => Format::fmtDate($day),
                'short'    => (string) (int) substr($day, 8, 2),
                'views'    => $views,
                // Integer percent: the bar is a height in CSS and a fraction of
                // a percent is a fraction of a pixel nobody can see. FLOORED AT
                // 1 for any day that had a visit, because round() takes a day
                // with two landings beside a peak of a thousand down to zero -
                // and a zero-height bar on a day somebody came is the one
                // mistake this whole class exists to avoid.
                'pct'      => self::height($views, $peak),
                'peak'     => $isTop,
                'labelled' => $every || $i === 0 || $i === $last || $isTop,
            ];
        }

        return $out;
    }

    /**
     * A bar's height as a percentage of the busiest day's: 0 only for a day
     * that truly had nothing.
     */
    private static function height(int $views, int $peak): int
    {
        if ($views <= 0 || $peak <= 0) {
            return 0;
        }

        return max(1, (int) round($views / $peak * self::BAR_MAX_PCT));
    }

    /** The busiest day's count, 0 when nothing was counted. */
    public function peakViews(): int
    {
        return $this->landingsByDay === [] ? 0 : max($this->landingsByDay);
    }

    /** "busiest day: 30 Sept 2026, 41 landings", "" when there is no data. */
    public function peakLabel(): string
    {
        $peak = $this->peakViews();

        if ($peak <= 0) {
            return '';
        }

        foreach ($this->bars() as $bar) {
            if ($bar['peak']) {
                return 'busiest day: ' . $bar['label'] . ', ' . Format::fmtNum((float) $peak)
                    . ' landing' . ($peak === 1 ? '' : 's');
            }
        }

        return '';
    }

    /**
     * What the chart is, said out loud for a screen reader and for anybody who
     * cannot see the bars.
     */
    public function chartSummary(): string
    {
        return 'Landings per day, ' . $this->rangeLabel() . '. '
            . ($this->peakLabel() === '' ? 'Nothing was counted in these dates.' : ucfirst($this->peakLabel()) . '.');
    }

    /**
     * The sentence that keeps an empty first week from reading as an empty
     * first week of TRAFFIC.
     *
     * This is the one piece of copy on the page that exists because of a date
     * rather than because of a number: the site has been taking quotes far
     * longer than it has been counting arrivals, so a range that starts before
     * the counter shipped has a real zero in it that means "not measured". The
     * owner must not read it as "nobody came".
     */
    public function countingNote(): string
    {
        $shipped = $this->countingFrom === '' ? '' : Format::fmtDate($this->countingFrom);

        if ($shipped === '') {
            return 'Landings are counted from the day the counter was switched on. Quotes go back further.';
        }

        $note = 'Landings have only been counted since ' . $shipped
            . ', when this was switched on. Any day before that shows nothing because nobody was counting, not because nobody came.';

        if ($this->firstCountedDay !== '') {
            $note .= ' The first day with a landing on it is ' . Format::fmtDate($this->firstCountedDay) . '.';
        }

        // The quote numbers below have no such gap, and saying so is what stops
        // the whole screen being distrusted because one half of it is young.
        return $note . ' The quote figures below are complete: every price the site has ever shown is in them.';
    }

    /** Does the range reach back before anything was counted? */
    public function rangeStartsBeforeCounting(): bool
    {
        return $this->countingFrom !== '' && $this->from->format('Y-m-d') < $this->countingFrom;
    }

    /* ------------------------------------------------------------------ *
     * Quotes: countries, weights, services
     * ------------------------------------------------------------------ */

    /**
     * The quotes figure and whether it is a measurement.
     *
     * @return array{value:int,has:bool}
     */
    public function quotes(): array
    {
        return ['value' => $this->quotesTotal, 'has' => $this->quotesTotal > 0];
    }

    /**
     * Countries chosen in the quote tool, ranked, with counts and share.
     *
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    public function countriesRanked(): array
    {
        // Of every quote in the range, not of the twenty printed - see ranked().
        return $this->ranked($this->countries, true, $this->quotesTotal);
    }

    /** @return list<array{label:string,n:int,share:string,pct:int}> */
    public function servicesRanked(): array
    {
        return $this->ranked($this->services);
    }

    /**
     * The weight bands, in the order the quote tool shows its buttons - NOT
     * ranked.
     *
     * A ladder is read as a ladder: the owner is looking for where the weight
     * sits, and sorting 10 kg above 1 kg because it is busier destroys the one
     * thing the shape of this list tells him.
     *
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    public function bandsInOrder(): array
    {
        $rows = [];

        foreach (QuoteStats::bandKeys() as $key) {
            $rows[] = ['label' => self::bandLabel($key), 'n' => $this->bands[$key] ?? 0];
        }

        return $this->ranked($rows, false);
    }

    /**
     * The band key as the quote tool words it.
     *
     * "1 kg" and not "up to 1 kg", because that is what the button says; the
     * note under the table explains that a band is everything up to it, once,
     * rather than nine times in nine labels.
     */
    public static function bandLabel(string $key): string
    {
        return $key === QuoteStats::OVER ? 'over 25 kg' : $key . ' kg';
    }

    /* ------------------------------------------------------------------ *
     * Landings: where from, on what, which page
     * ------------------------------------------------------------------ */

    /**
     * Referring sites, busiest first, as a share of EVERY landing.
     *
     * The list is capped by the controller, so the share is of
     * `landingsTotal` and not of the rows - the same rule as the countries.
     * The empty host is a typed address, a bookmark, or an app that sends no
     * Referer, and it is worded as exactly that rather than as a bare "Direct"
     * that reads like a channel somebody chose.
     *
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    public function referrersRanked(): array
    {
        $rows = [];
        foreach ($this->referrers as $host => $n) {
            $rows[] = ['label' => $host === '' ? 'Direct, or an app that does not say' : (string) $host, 'n' => $n];
        }

        return $this->ranked($rows, true, $this->landingsTotal);
    }

    /**
     * Phone or desktop. "Desktop" says "or tablet" because PageViews::device()
     * counts a tablet there, and the label should not claim a precision the
     * count does not have.
     *
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    public function devicesRanked(): array
    {
        $rows = [];
        foreach ($this->devices as $device => $n) {
            $rows[] = [
                'label' => match ((string) $device) {
                    PageViews::PHONE   => 'Phone',
                    PageViews::DESKTOP => 'Computer or tablet',
                    default            => (string) $device,
                },
                'n' => $n,
            ];
        }

        return $this->ranked($rows);
    }

    /**
     * The page each landing arrived on. The address is the label - it is what
     * the owner types and what a shared link shows - except for the home page
     * and for PageViews::OTHER, whose "/other" is a bucket and not an address
     * anybody could visit.
     *
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    public function pagesRanked(): array
    {
        $rows = [];
        foreach ($this->pages as $path => $n) {
            $rows[] = [
                'label' => match ((string) $path) {
                    '/'               => 'Home page',
                    PageViews::OTHER  => 'Any other address',
                    default           => (string) $path,
                },
                'n' => $n,
            ];
        }

        return $this->ranked($rows, true, $this->landingsTotal);
    }

    /* ------------------------------------------------------------------ *
     * The one number that matters
     * ------------------------------------------------------------------ */

    /**
     * How many quotes ended with the WhatsApp button being pressed.
     *
     * NOT CALLED A CONVERSION, on the brief's instruction and for a better
     * reason than style: a conversion rate is a claim about money, and this is
     * a count of taps. The customer who tapped may never have sent the message;
     * the one who did may have haggled and gone elsewhere. What the number is
     * good for is comparing weeks - the same question, asked the same way, of
     * the same button - and the wording says exactly that much.
     *
     * The share is null rather than 0.0 when no quotes were priced at all. A
     * percentage of nothing is not nought per cent.
     *
     * @return array{value:int,of:int,share:?float,has:bool}
     */
    public function whatsApp(): array
    {
        return [
            'value' => $this->whatsAppPressed,
            'of'    => $this->quotesTotal,
            'share' => $this->quotesTotal > 0 ? $this->whatsAppPressed / $this->quotesTotal * 100 : null,
            'has'   => $this->quotesTotal > 0,
        ];
    }

    /** "12.8%", or "-" when there were no quotes to take a share of. */
    public function whatsAppShare(): string
    {
        $share = $this->whatsApp()['share'];

        return $share === null ? "-" : self::pct($share);
    }

    /** "23 of 180 quotes finished on the WhatsApp button". */
    public function whatsAppSentence(): string
    {
        $wa = $this->whatsApp();

        if (!$wa['has']) {
            return 'no prices were shown in these dates, so there was nothing to press';
        }

        return Format::fmtNum((float) $wa['value']) . ' of ' . Format::fmtNum((float) $wa['of'])
            . ' quote' . ($wa['of'] === 1 ? '' : 's');
    }

    /* ------------------------------------------------------------------ *
     * Shared
     * ------------------------------------------------------------------ */

    /**
     * Every day in the range as "Y-m-d", oldest first.
     *
     * @return list<string>
     */
    private function days(): array
    {
        $from = $this->from < $this->to ? $this->from : $this->to;
        $to   = $this->from < $this->to ? $this->to : $this->from;

        $day  = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $out  = [];

        // Walked from noon for the same reason QuoteStats::window() does: a day
        // added to a midnight is twenty-four hours, which is not the next
        // midnight in a zone that moves its clocks.
        $cursor = new \DateTimeImmutable($day . ' 12:00:00', $from->getTimezone());
        $step   = new \DateInterval('P1D');

        while ($day <= $last) {
            $out[] = $day;
            $cursor = $cursor->add($step);
            $day    = $cursor->format('Y-m-d');
        }

        return $out;
    }

    /**
     * Rows with their share of the total and their bar width worked out.
     *
     * The share is of the WHOLE total the caller counted, not of the rows
     * printed: a top-twenty list of two hundred countries whose shares add up
     * to 100% is a list that has quietly dropped the other hundred and eighty.
     * A list that is CAPPED therefore has to be handed that whole total as
     * `$of` - summing the rows it was given is only right for a list that is
     * already complete (the bands, the services). It was not, for countries:
     * with thirty countries quoted, the UK at 50 of 400 read 14.3% of the top
     * twenty instead of its true 12.5% (fixed 2026-10-25). A `$of` smaller than
     * the rows' own sum is not believed, so a stale total can never print a
     * share over 100%.
     *
     * `pct` is of the biggest row so the bars use the full width; `share` is the
     * real proportion and is the number the owner reads. Keeping them apart is
     * the whole reason this method exists rather than one number doing both
     * jobs badly.
     *
     * @param  list<array{label:string,n:int}>                          $rows
     * @param  ?int                                                     $of   the whole total, when $rows is capped
     * @return list<array{label:string,n:int,share:string,pct:int}>
     */
    private function ranked(array $rows, bool $dropEmpty = true, ?int $of = null): array
    {
        $total = 0;
        $top   = 0;
        foreach ($rows as $row) {
            $total += $row['n'];
            $top = max($top, $row['n']);
        }

        if ($of !== null) {
            $total = max($total, $of);
        }

        $out = [];
        foreach ($rows as $row) {
            if ($dropEmpty && $row['n'] <= 0) {
                continue;
            }

            $out[] = [
                'label' => $row['label'],
                'n'     => $row['n'],
                'share' => $total > 0 ? self::pct($row['n'] / $total * 100) : "-",
                'pct'   => $top > 0 ? (int) round($row['n'] / $top * self::BAR_MAX_PCT) : 0,
            ];
        }

        return $out;
    }

    /**
     * A percentage with one decimal, and no decimal when it is a whole number.
     *
     * "12.8%" is worth the decimal on a hundred quotes; "50.0%" on two of four
     * reads as a measurement far more precise than two of four is.
     */
    private static function pct(float $n): string
    {
        $rounded = round($n, 1);

        return ($rounded === floor($rounded) ? (string) (int) $rounded : number_format($rounded, 1)) . '%';
    }

    /** Does the range end on today, as every preset's does? */
    private function endsToday(): bool
    {
        // Clock::now(), not `new DateTimeImmutable('now')`: the tests freeze
        // time, and a preset that lit up only on the real calendar day would be
        // untestable and would light up wrongly at five in the morning.
        return $this->to->format('Y-m-d') === Clock::now()->format('Y-m-d');
    }
}
