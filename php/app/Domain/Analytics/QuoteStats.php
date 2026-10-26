<?php

declare(strict_types=1);

namespace Speedat\Domain\Analytics;

use Speedat\Core\Clock;
use Speedat\Core\Db;

/**
 * Reading the quote log as numbers: which country, which weight, which
 * service, and how many of them ended with the WhatsApp button.
 *
 * NOTHING HERE IS RECORDED BY THIS CLASS, AND THAT IS THE POINT. `quotes` has
 * had dest_id, service_id, billable_g and `booked` on every row since the site
 * shipped (Domain\Quotes\QuoteRepository), so the owner's three questions -
 * which country, which weight category, how many actually pressed Book - are
 * already answered by data on disk. They were simply never added up. A second
 * counter for any of it would be two tables that disagree by next month;
 * `Domain\Analytics\PageViews` records the one thing `quotes` cannot know, the
 * visitor who never asked for a price, and this class only reads.
 *
 * THE RANGE IS IN LOCAL DATES AND THE COLUMN IS IN UTC, so the conversion
 * happens here, once. `quotes.created_at` is UTC like every other DATETIME in
 * the schema; `page_views.day` is the owner's own day (Asia/Karachi, and
 * db/migrations/0003 says why). Karachi is UTC+5, so "the 3rd" as the owner
 * means it is 2001-02-02 19:00 UTC to 2001-02-03 19:00 UTC. Comparing the
 * column against a bare local date would file every quote taken before 5 a.m.
 * under the previous day and put the landings and the quotes on the screen on
 * two different calendars. See window().
 *
 * VALUES IN, VALUES OUT (app/Domain/README.md rule 2). Two \DateTimeImmutable
 * dates in, `array<string,int>` out, and the keys are IDS AND BAND KEYS rather
 * than words: `dest_id` is turned into "United Kingdom" by
 * Shipments\NameLookup, which already knows what a country retired from the
 * rate card used to be called, and the weight bands are named by the screen.
 * A domain class that invented "over 25 kg" would make every caller live with
 * that choice of words.
 */
final class QuoteStats
{
    /**
     * The weight buttons the quote tool shows, in kilograms.
     *
     * The same eight as `View\CalculatorData::KG_BUTTONS` and
     * `calculator.js`'s `KG_BUTTONS`, because the owner asked for "the weight
     * category" and a category he was never offered is not one he can act on.
     * They are NOT read from that class: these are the buckets the numbers on
     * the screen were counted into, and a list that silently changed shape
     * would silently re-bucket the history beside it.
     *
     * @var list<int>
     */
    public const BANDS = [1, 2, 3, 5, 10, 15, 20, 25];

    /**
     * Everything heavier than the top button.
     *
     * `settings.maxKg` is 25 on the live card, so this band should be empty -
     * which is exactly why it is printed rather than dropped. A number in it
     * means something priced a parcel the calculator says it cannot, and that
     * is worth seeing.
     */
    public const OVER = '25+';

    /**
     * The heaviest whole kilogram the grouping keeps apart, one above the top
     * band.
     *
     * `billable_g` is INT UNSIGNED, so in principle it reaches 4,294 tonnes and
     * a GROUP BY on the kilogram could return four million rows into PHP.
     * Clamping in SQL bounds the result at twenty-six rows whatever is in the
     * column, and loses nothing: everything at or above this lands in one band
     * anyway.
     */
    private const CLAMP_KG = 26;

    /** Rows at most from the country ranking, and the ceiling on what a caller may ask for. */
    private const MAX_ROWS = 100;

    /** How many quotes were priced in the range. */
    public function total(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$start, $end] = self::window($from, $to);

        return (int) Db::value(
            'SELECT COUNT(*) FROM quotes WHERE created_at >= ? AND created_at < ?',
            [$start, $end],
            0
        );
    }

    /**
     * How many of them ended with the WhatsApp button being pressed.
     *
     * `booked = 1` is written by the browser when the customer taps "Book on
     * WhatsApp" - the log is fired twice for such a quote, once when the price
     * was shown and again on the tap, and the second write updates the first
     * row (QuoteRepository's upsert). So this is a count of quotes that ended
     * on WhatsApp, not of shipments: the conversation may still have gone
     * nowhere. The screen says so in those words.
     */
    public function pressedWhatsApp(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$start, $end] = self::window($from, $to);

        return (int) Db::value(
            'SELECT COUNT(*) FROM quotes WHERE booked = 1 AND created_at >= ? AND created_at < ?',
            [$start, $end],
            0
        );
    }

    /**
     * The local date of the first quote recorded WITHOUT the Book button, or
     * "" when there is none yet.
     *
     * Until 2026-10-25 the browser logged a quote only when "Book on WhatsApp"
     * was tapped, so every older row is booked = 1: "prices shown" was really
     * "prices booked" and the WhatsApp share read 100%. The first unbooked row
     * is the first day the log counts every price shown - asked of the data,
     * like the landings counter's first day, rather than typed in as a date.
     */
    public function shownSince(): string
    {
        $first = Db::value('SELECT MIN(created_at) FROM quotes WHERE booked = 0');
        if ($first === null || $first === '') {
            return '';
        }

        return (new \DateTimeImmutable((string) $first, new \DateTimeZone('UTC')))->setTimezone(Clock::zone())->format('Y-m-d');
    }

    /**
     * Quotes per destination id, busiest first.
     *
     * @param  int                $limit rows at most, 1..100
     * @return array<string,int>  dest_id => quotes
     */
    public function byDestination(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 20): array
    {
        [$start, $end] = self::window($from, $to);

        return self::counted(Db::all(
            'SELECT dest_id AS label, COUNT(*) AS n
               FROM quotes
              WHERE created_at >= ? AND created_at < ?
           GROUP BY dest_id
           ORDER BY COUNT(*) DESC, dest_id
              LIMIT ?',
            [$start, $end, max(1, min(self::MAX_ROWS, $limit))]
        ));
    }

    /**
     * Quotes per service id, busiest first.
     *
     * Not limited: there are three services on the card and a rate document
     * with a hundred of them is a different problem than a long list here.
     *
     * @return array<string,int> service_id => quotes
     */
    public function byService(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$start, $end] = self::window($from, $to);

        return self::counted(Db::all(
            'SELECT service_id AS label, COUNT(*) AS n
               FROM quotes
              WHERE created_at >= ? AND created_at < ?
           GROUP BY service_id
           ORDER BY COUNT(*) DESC, service_id',
            [$start, $end]
        ));
    }

    /**
     * Quotes per weight band, in the order the quote tool shows the buttons.
     *
     * THE BANDING IS DONE IN PHP AND THE NARROWING IN SQL, deliberately. A CASE
     * expression with nine arms in it would be a second copy of the band
     * ladder, in a language the tests cannot call; band() is the one decider
     * and the SQL only has to hand over something small enough to loop over.
     * `CEIL(billable_g / 1000)` is the same rounding the grid charges at - a
     * parcel is billed at the next whole kilogram up - so the band a quote is
     * counted in is the band the customer was quoted on.
     *
     * EVERY BAND IS PRESENT, including the empty ones. A ladder with 3 kg
     * missing from it reads as a ladder without a 3 kg option, when what
     * happened is that nobody asked for one this month.
     *
     * The keys ARRIVE BACK AS INTS for the eight numeric bands, because PHP
     * coerces a numeric string array key and there is no way to stop it. A
     * lookup by the string key still finds the same slot, so callers can use
     * bandKeys() as written; only code that compares `array_keys()` has to
     * know.
     *
     * @return array<array-key,int> band key => quotes
     */
    public function byWeightBand(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$start, $end] = self::window($from, $to);

        $out = array_fill_keys(self::bandKeys(), 0);

        // The clamp is written into the statement rather than bound, the way
        // `Shipments\NameLookup` writes its LIMIT: it is a private integer
        // constant of this class, never anything a caller or a visitor said,
        // and a bound parameter inside LEAST() arrives as a quoted string on an
        // emulated prepare - where LEAST would then compare '26' with a number
        // as text and answer nonsense for every weight over 9 kg.
        $rows = Db::all(
            'SELECT LEAST(CEIL(billable_g / 1000), ' . self::CLAMP_KG . ') AS kg, COUNT(*) AS n
               FROM quotes
              WHERE created_at >= ? AND created_at < ?
           GROUP BY kg',
            [$start, $end]
        );

        foreach ($rows as $row) {
            $band = self::bandOfKilograms((int) ($row['kg'] ?? 0));
            $out[$band] += (int) ($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * Every band key, in ladder order. The screen prints them in this order
     * and names them; see the class note on why the words are not here.
     *
     * @return list<string>
     */
    public static function bandKeys(): array
    {
        $keys = array_map(strval(...), self::BANDS);
        $keys[] = self::OVER;

        return $keys;
    }

    /**
     * The band a billed weight in GRAMS falls in.
     *
     * The convenience form, for a caller holding a `billable_g` rather than a
     * kilogram - which is everything outside this class. It rounds the way the
     * grid charges (up, to the next whole kilogram) and then asks the one
     * decider, so band(4_500) and bandOfKilograms(5) are the same answer by
     * construction rather than by two lists agreeing.
     */
    public static function band(int $grams): string
    {
        return self::bandOfKilograms(self::kilograms($grams));
    }

    /**
     * The band a whole number of kilograms falls in: the first button at or
     * above it, or OVER.
     *
     * A weight of zero answers the lightest band. It should not happen -
     * `Domain\Quotes\QuoteInput` refuses an empty parcel long before the row is
     * written - but a quote that somehow has one is still a quote somebody
     * asked for, and dropping it would make the bands add up to less than the
     * total with nothing on the screen to explain the difference.
     */
    public static function bandOfKilograms(int $kg): string
    {
        foreach (self::BANDS as $band) {
            if ($kg <= $band) {
                return (string) $band;
            }
        }

        return self::OVER;
    }

    /** Grams billed, rounded up to the whole kilogram the grid charges at. */
    private static function kilograms(int $grams): int
    {
        return $grams <= 0 ? 0 : (int) ceil($grams / 1000);
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private static function counted(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            // COUNT() arrives as a string from PDO whatever STRINGIFY_FETCHES
            // says, so the cast is not optional - the same reason
            // PageViews::labelled() casts its SUM.
            $out[(string) ($row['label'] ?? '')] = (int) ($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * The two UTC timestamps a range of LOCAL dates covers: the start of the
     * first day, and the start of the day after the last.
     *
     * HALF-OPEN, not BETWEEN. `created_at` is a DATETIME with no fractional
     * seconds today, so `<= 23:59:59` would work - and would quietly start
     * losing the last second of the range the day somebody widens the column.
     * `< the next midnight` cannot.
     *
     * Swapped dates are sorted rather than refused, as PageViews::range() sorts
     * them: a range with its ends the wrong way round matches nothing at all,
     * and an empty screen is a bug report about the analytics rather than about
     * the date boxes that sent them.
     *
     * The dates are REBUILT in the display zone from their Y-m-d rather than
     * used as they arrive. A caller holding a UTC-zoned midnight would
     * otherwise shift the whole window five hours, which is a four-figure
     * difference on a busy month and invisible in any single number.
     *
     * @return array{string,string}
     */
    private static function window(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $a = $from->format('Y-m-d');
        $b = $to->format('Y-m-d');

        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        $zone = Clock::zone();

        // The day after $b is found from NOON and not from its midnight. Adding
        // a day to a midnight adds twenty-four hours, which in a zone that
        // moves its clocks is not the next midnight; from noon it always is.
        // Karachi has not observed daylight saving since 2009, but
        // `app.timezone` is configuration and a range that quietly loses an
        // hour is the kind of error nobody finds by looking at a total.
        $next = (new \DateTimeImmutable($b . ' 12:00:00', $zone))
            ->add(new \DateInterval('P1D'))
            ->format('Y-m-d');

        return [
            Clock::sql(new \DateTimeImmutable($a . ' 00:00:00', $zone)),
            Clock::sql(new \DateTimeImmutable($next . ' 00:00:00', $zone)),
        ];
    }
}
