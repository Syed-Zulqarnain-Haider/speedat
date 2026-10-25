<?php

declare(strict_types=1);

namespace Speedat\Domain\Analytics;

use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Domain\Seo\Routes;

/**
 * Landings: how many people arrive, on which page, from where, on what kind of
 * screen. The writer and the reader of `page_views` (db/migrations/0003).
 *
 * WHAT THIS DOES NOT DO, BECAUSE SOMETHING ELSE ALREADY DOES IT. "Which
 * country" and "which weight" come out of `quotes`, which gets a row every
 * time a price is shown - dest_id, service_id, billable_g, and in `detail` the
 * pieces, the add-ons and whether Book was tapped (Domain\Quotes\QuoteRepository).
 * Counting any of that a second time here would be two tables that disagree by
 * next month. The only thing missing was the visitor who never priced
 * anything, and that is all this class records.
 *
 * VALUES IN, VALUES OUT. app/Domain/README.md rule 2: a domain class takes
 * values, not $_SERVER and not a Request. record() is handed the path, the
 * User-Agent and the Referer - which is also why every decision below (is this
 * an asset, is this a bot, is this a phone) can be tested without a web server
 * or a database.
 *
 * THE WRITE NEVER THROWS, exactly as Core\Audit's does not. It happens on the
 * request that is trying to draw a page, and a visitor who came to read about
 * courier rates must never see an error because the counter could not be
 * incremented. A failure is logged loudly and swallowed; see record().
 *
 * NO IP, NOT EVEN HASHED, and no third-party script - the migration says why.
 */
final class PageViews
{
    public const PHONE = 'phone';

    public const DESKTOP = 'desktop';

    /**
     * Where every path that is not one of the site's own pages is counted.
     *
     * WHY A BUCKET AND NOT THE PATH ITSELF. The raw path is whoever is
     * typing, and a table with no sweeper on a plan with a disk quota is not
     * the place to keep it. Since 2026-10-25 the counter only runs on a 200
     * HTML page (countable(), and Core\Bootstrap::web() for where), so a
     * scanner's wordlist of 404s no longer reaches here at all - but a 200 on
     * an address that is not one of Seo\Routes' pages still can (a page that
     * takes a parameter, a route added without a Routes entry), and the bucket
     * is still what keeps that to one row rather than one per address.
     */
    public const OTHER = '/other';

    /**
     * Prefixes that are never a landing.
     *
     * Routes::DISALLOWED is the same list robots.txt and the sitemap use, so
     * "/admin" and "/api" are excluded from one place rather than three. The two
     * added here are the operational endpoints (app/routes.php): a monitor
     * calling /health every minute is not a visitor, and counting it would put
     * forty thousand "landings" a month on top of the owner's numbers.
     *
     * @var list<string>
     */
    private const OPERATIONAL = ['/ops', '/health'];

    /**
     * User-Agents that are not a person.
     *
     * Deliberately a coarse list of OBVIOUS ones, which is all the brief asks
     * for and all that can be honest: dressing this up as bot detection would
     * promise an accuracy no substring match has. Everything here either says
     * what it is (bot, crawler, spider), is a tool rather than a browser (curl,
     * wget, python), is a link preview (facebookexternalhit, WhatsApp,
     * Slack) or is a monitor. A crawler that lies about its
     * User-Agent is counted as a visitor, and that is the right way for this to
     * fail - an inflated count of real-looking visits, never a page view thrown
     * away because a pattern was too clever.
     *
     * NO BARE "telegram" (removed 2026-10-25). Telegram's in-app browser is a
     * Chrome WebView whose User-Agent ends in "Telegram-Android/11.x" - a real
     * person who tapped a link in a chat - and the bare word threw every one
     * of those visits away. Telegram's link-preview fetcher calls itself
     * "TelegramBot" and is still caught, by "bot". The same is NOT true of
     * "whatsapp": WhatsApp opens links in the phone's own browser, so the only
     * thing that ever sends that word is the preview fetcher.
     */
    private const BOTS = '/bot|crawler?|spider|slurp|curl|wget|python|java|scrapy|headless|phantom|'
        . 'lighthouse|pagespeed|pingdom|uptime|monitor|preview|fetcher|archiver|'
        . 'facebookexternalhit|whatsapp|slackbot|discord|semrush|ahrefs|mj12|dotbot/i';

    /** A file extension at the end of a path: .css, .js, .png, .webmanifest. */
    private const ASSET = '/\.[a-z0-9]{1,12}$/i';

    /** Longest referrer host kept, as a hostname can be, and the column is wider. */
    private const MAX_HOST = 190;

    /**
     * Is this finished response one a landing can be counted on?
     *
     * A GET (not a HEAD: that is a monitor or a link checker asking whether a
     * page exists, and nobody read anything), answered 200, with an HTML body.
     *
     * WHY THE ANSWER AND NOT THE QUESTION (2026-10-25). The counter used to run
     * before routing, on the request alone, and that measured the wrong thing:
     * `/services/` (a 301) and the `/services` it redirects to were two
     * landings for one visit, `/FAQ` (a 404) counted as `/faq`, and a scanner
     * with a browser User-Agent working through /wp-admin, /backup and the rest
     * of its list put a spike into "/other" - which total() and byDay() add up
     * - so the Landings and Busiest day tiles jumped on the nights somebody
     * prodded the site. A redirect, an error page, a JSON answer and a
     * download are none of them a page somebody read.
     *
     * Values in, like everything else here: the caller reads the status and
     * the Content-Type off whatever it sent.
     */
    public static function countable(string $method, int $status, string $contentType): bool
    {
        return strtoupper($method) === 'GET'
            && $status === 200
            && str_starts_with(strtolower(ltrim($contentType)), 'text/html');
    }

    /**
     * Count one landing. Returns whether a row was written, and never throws.
     *
     * The boolean is for the tests and for anybody reading a log - the caller
     * in Core\Bootstrap ignores it, because there is nothing it could usefully
     * do about a false either way.
     *
     * A LANDING IS AN ARRIVAL, NOT A CLICK (2026-10-25). `$ownHosts` are the
     * site's own hostnames - `app.url`'s and the one the request came in on,
     * in any form referrerHost() accepts - and a request whose Referer is one
     * of them is somebody already on the site: an internal link, the page the
     * contact form's POST redirected back to, a reload of a page reached that
     * way. Counting those made a visitor from Google who read four pages and
     * sent a message five "landings", four of them "from speedat.co", and the
     * headline figure several times what actually arrived. They are not
     * bucketed separately either: the table's primary key is (day, path,
     * device, referrer) and a row of "came from ourselves" answers no question
     * the owner asks.
     *
     * THE ORDER MATTERS: the cheap value checks come first, so a request for a
     * missing stylesheet, a crawl of /admin or a click from one page to the
     * next costs no database connection at all. On this plan a connection is
     * the expensive part of the request.
     *
     * @param list<string> $ownHosts the site's own hosts or URLs; "" entries are ignored
     */
    public function record(string $path, string $userAgent = '', string $referer = '', array $ownHosts = []): bool
    {
        $page = self::page($path);

        if ($page === null || self::isBot($userAgent)) {
            return false;
        }

        $from = self::referrerHost($referer);

        if ($from !== '' && self::isOwnHost($from, $ownHosts)) {
            return false;
        }

        try {
            Db::exec(
                'INSERT INTO page_views (day, path, device, referrer_host, views)
                      VALUES (:day, :path, :device, :referrer_host, 1)
                 ON DUPLICATE KEY UPDATE views = views + 1',
                [
                    ':day'           => Clock::now()->format('Y-m-d'),
                    ':path'          => Caps::fit('page_views.path', $page),
                    ':device'        => self::device($userAgent),
                    ':referrer_host' => Caps::fit('page_views.referrer_host', $from),
                ],
            );
        } catch (\Throwable $e) {
            // \Throwable and not \PDOException: a database that is down throws
            // a RuntimeException out of Db::pdo() before any statement is
            // prepared, and that is the failure most likely to happen here.
            Log::exception('page_view_write_failed', $e, ['path' => $page]);

            return false;
        }

        return true;
    }

    /**
     * The bucket a path is counted under, or null when it is not counted at
     * all.
     *
     * Assets are refused even though an asset never reaches PHP - the front
     * controller says so, and .htaccess and the dev router both serve what
     * exists on disk. A MISSING one does reach PHP, as a 404, and so does every
     * request for /wp-login.php that a scanner sends. The extension rule keeps
     * both out, and keeps them out on any future host whose rewrite rules are
     * arranged differently.
     */
    public static function page(string $path): ?string
    {
        // The router's own normalisation, plus case folding: /FAQ and /faq are
        // one page to everybody except a GROUP BY.
        $path = strtolower(rtrim('/' . ltrim($path, '/'), '/'));
        if ($path === '') {
            $path = '/';
        }

        if (preg_match(self::ASSET, $path) === 1) {
            return null;
        }

        foreach ([...Routes::DISALLOWED, ...self::OPERATIONAL] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return null;
            }
        }

        foreach (Routes::all() as $route) {
            if ($route->path === $path) {
                return $path;
            }
        }

        return self::OTHER;
    }

    /**
     * Phone or desktop, from the User-Agent.
     *
     * A tablet answers "desktop". iPadOS Safari calls itself Macintosh and
     * Android tablets are indistinguishable from large phones, so a third
     * bucket would be wrong about half of what went into it - and a number that
     * is wrong half the time is worse than a coarser one that is not.
     */
    public static function device(string $userAgent): string
    {
        return preg_match('/Mobi|Android|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $userAgent) === 1
            ? self::PHONE
            : self::DESKTOP;
    }

    /**
     * Is this something other than a person with a browser?
     *
     * An EMPTY User-Agent counts as one. Every browser ever shipped sends the
     * header; a request without it is a script that did not bother, and the
     * only thing it would add to the owner's numbers is noise.
     */
    public static function isBot(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    /**
     * The host of a referring URL - never the URL.
     *
     * A referring URL carries the referrer's own path and query, which is where
     * a search term, a campaign id or somebody's email address lives. The host
     * is the whole of the answer to "where did they come from", so the rest is
     * dropped here and never stored.
     *
     * "www." goes, so www.google.com and google.com are one row rather than
     * two. Anything that is not a plausible hostname becomes "" - the same as
     * no referrer - because a Referer header is whatever the client typed and
     * this is a primary key.
     */
    public static function referrerHost(string $referer): string
    {
        $host = parse_url(trim($referer), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Letters, digits, dots and hyphens: an internationalised domain
        // arrives punycoded, so this rejects nothing real.
        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,' . (self::MAX_HOST - 1) . '})$/', $host) === 1 ? $host : '';
    }

    /**
     * Is this referring host one of the site's own?
     *
     * Each entry goes through referrerHost() too, so "https://www.speedat.co",
     * "speedat.co" and the Host header's "speedat.co:443" all compare as the
     * same "speedat.co" the Referer was reduced to. A bare host is given a
     * scheme first because parse_url() reads "speedat.co" as a path.
     *
     * @param list<string> $ownHosts
     */
    private static function isOwnHost(string $host, array $ownHosts): bool
    {
        foreach ($ownHosts as $own) {
            $own = trim($own);
            if ($own === '') {
                continue;
            }

            if (self::referrerHost(str_contains($own, '://') ? $own : 'http://' . $own) === $host) {
                return true;
            }
        }

        return false;
    }

    /* ===================================================================== */
    /*  READING - what the admin screen asks                                  */
    /* ===================================================================== */

    /**
     * Total landings in the range, both dates included.
     *
     * COALESCE, so an empty range is 0 and not null: a screen that has to
     * remember to turn null into nought is a screen that one day prints
     * nothing where a zero belongs.
     */
    public function total(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$start, $end] = self::range($from, $to);

        return (int) Db::value(
            'SELECT COALESCE(SUM(views), 0) FROM page_views WHERE day BETWEEN ? AND ?',
            [$start, $end],
            0
        );
    }

    /**
     * Landings per day, oldest first.
     *
     * Days with no traffic are absent rather than zero - the table has no row
     * for them and inventing one here would mean this method had to know what
     * "every day in the range" is when the range is a month long and the site
     * is three days old. A chart fills its own gaps; see the keys.
     *
     * @return array<string,int> "Y-m-d" => views
     */
    public function byDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->map(
            'SELECT day AS label, SUM(views) AS views
               FROM page_views
              WHERE day BETWEEN ? AND ?
           GROUP BY day
           ORDER BY day',
            $from,
            $to
        );
    }

    /**
     * Landings per page, busiest first.
     *
     * @return array<string,int> path => views
     */
    public function byPath(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->map(
            'SELECT path AS label, SUM(views) AS views
               FROM page_views
              WHERE day BETWEEN ? AND ?
           GROUP BY path
           ORDER BY SUM(views) DESC, path',
            $from,
            $to
        );
    }

    /**
     * Landings per kind of screen, busiest first.
     *
     * @return array<string,int> "phone" | "desktop" => views
     */
    public function byDevice(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->map(
            'SELECT device AS label, SUM(views) AS views
               FROM page_views
              WHERE day BETWEEN ? AND ?
           GROUP BY device
           ORDER BY SUM(views) DESC, device',
            $from,
            $to
        );
    }

    /**
     * Landings per referring host, busiest first, capped.
     *
     * The empty key is direct traffic - a typed address, a bookmark, an app
     * that sends no Referer - and it is normally the biggest one. It is
     * deliberately NOT relabelled here: the words on a screen belong to the
     * screen, and a domain class that invents "Direct" makes every caller live
     * with that choice of word.
     *
     * @param  int                $limit rows at most, 1..200
     * @return array<string,int>  host => views, "" for direct
     */
    public function byReferrer(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 20): array
    {
        [$start, $end] = self::range($from, $to);

        $rows = Db::all(
            'SELECT referrer_host AS label, SUM(views) AS views
               FROM page_views
              WHERE day BETWEEN ? AND ?
           GROUP BY referrer_host
           ORDER BY SUM(views) DESC, referrer_host
              LIMIT ?',
            [$start, $end, max(1, min(200, $limit))]
        );

        return self::labelled($rows);
    }

    /**
     * One grouped query, run and turned into label => count.
     *
     * Every read above has the same shape, so the shape is written once: a
     * second hand-written loop is a second place for the SUM to come back as a
     * string and be compared with an int.
     *
     * @return array<string,int>
     */
    private function map(string $sql, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$start, $end] = self::range($from, $to);

        return self::labelled(Db::all($sql, [$start, $end]));
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private static function labelled(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            // SUM() comes back as a DECIMAL, which PDO hands over as a string
            // whatever STRINGIFY_FETCHES says, so the cast is not optional.
            $out[(string) ($row['label'] ?? '')] = (int) ($row['views'] ?? 0);
        }

        return $out;
    }

    /**
     * The two SQL dates of a range, in order.
     *
     * Swapped dates are sorted rather than refused: BETWEEN with the ends the
     * wrong way round matches nothing at all, and an empty chart is a bug
     * report about the analytics rather than about the date picker that sent
     * them.
     *
     * @return array{string,string}
     */
    private static function range(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $start = $from->format('Y-m-d');
        $end   = $to->format('Y-m-d');

        return $start <= $end ? [$start, $end] : [$end, $start];
    }
}
