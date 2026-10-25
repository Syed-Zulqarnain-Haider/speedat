<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Log;
use Speedat\Core\Paths;
use Speedat\Core\Str;

/**
 * What the public site reads: the published document and the price hold, each
 * cached across requests and stamped so a publish or a hold switch expires them
 * instantly. Port of src/lib/site/live.ts - `bust()` is the PHP twin of
 * `revalidateTag(SITE_TAG)`.
 *
 * THE STAMP, NOT THE CLOCK, IS WHAT MAKES THIS SAFE. The cache carries the
 * stamp it was written under; `bust()` writes a new stamp, so every cached file
 * is dead the instant it is written - no window in which a customer is quoted a
 * price the owner has just replaced, and no need to find and delete the files.
 * The time-to-live is only a backstop for a stamp file somebody deleted by hand.
 *
 * FALLING BACK IS DELIBERATE. An unreachable database serves the sample
 * document (version 0, sample notice on) rather than failing the page: a
 * courier site with sample prices and a "sample rates" banner beats a courier
 * site with a stack trace. The hold falls back to "off" for the same reason -
 * it is a business switch, not a security control.
 *
 * The fallback is never written to the cache. The TypeScript caches it for five
 * minutes because `unstable_cache` caches whatever the function returns; here a
 * blink of the database would otherwise pin sample prices to the site for five
 * minutes after it came back, and the retry costs one failed connection.
 */
final class Live
{
    /** Backstop lifetime, in seconds, for a cache entry whose stamp still matches. */
    public const TTL = 300;

    /** Memo for the current request only; a page renders the header, the board and the footer from one read. */
    private static ?PublishedVersion $site = null;
    private static ?HoldState $hold        = null;
    private static ?string $stamp          = null;

    /** The document the public site renders. Never throws. */
    public static function site(): PublishedVersion
    {
        if (self::$site !== null) {
            return self::$site;
        }

        $cached = self::read('site');
        if ($cached !== null) {
            $version     = isset($cached['version']) ? (int) $cached['version'] : 0;
            $publishedAt = isset($cached['publishedAt']) ? (string) $cached['publishedAt'] : '';
            $document    = isset($cached['doc']) && is_array($cached['doc']) ? $cached['doc'] : null;

            if ($document !== null) {
                return self::$site = new PublishedVersion(Migrate::migrate($document), $version, $publishedAt);
            }
        }

        try {
            $live = Repo::getLatestVersion();
        } catch (\Throwable $e) {
            Log::exception('live_site_fallback', $e);

            return self::$site = PublishedVersion::sample();
        }

        if ($live === null) {
            // An empty database is not a failure, but there is nothing to cache
            // either: the seed will be published in a moment.
            return self::$site = PublishedVersion::sample();
        }

        self::write('site', [
            'version'     => $live->version,
            'publishedAt' => $live->publishedAt,
            'doc'         => $live->data->toArray(),
        ]);

        return self::$site = $live;
    }

    /** The price hold. Never throws; an unreadable hold shows prices. */
    public static function hold(): HoldState
    {
        if (self::$hold !== null) {
            return self::$hold;
        }

        $cached = self::read('hold');
        if ($cached !== null && isset($cached['hold']) && is_array($cached['hold'])) {
            return self::$hold = HoldState::fromArray($cached['hold']);
        }

        try {
            $hold = Hold::get();
        } catch (\Throwable $e) {
            Log::exception('live_hold_fallback', $e);

            return self::$hold = HoldState::none();
        }

        self::write('hold', ['hold' => $hold->toArray()]);

        return self::$hold = $hold;
    }

    /**
     * Expire every cached read, everywhere, at once. Called by a publish and by
     * a hold switch - the two things that change what a customer must see now.
     */
    public static function bust(): void
    {
        self::forget();

        $dir = self::dir();
        if (!Paths::ensureWritable($dir)) {
            // Nothing cached means nothing stale; the site reads the database
            // on every request until the directory is writable again.
            Log::warn('live_cache_unwritable', ['dir' => $dir]);

            return;
        }

        @file_put_contents($dir . '/stamp', Str::token(8), LOCK_EX);
    }

    /** Drop the per-request memo. Tests and long-running CLI jobs; the files are untouched. */
    public static function forget(): void
    {
        self::$site  = null;
        self::$hold  = null;
        self::$stamp = null;
    }

    /* ---------------------------------------------------------- internals */

    private static function dir(): string
    {
        return Paths::cache() . '/site';
    }

    /**
     * The current stamp. A missing stamp file is created rather than treated as
     * "no cache": otherwise the first request after a deploy would write cache
     * files nothing could ever match.
     */
    private static function stamp(): string
    {
        if (self::$stamp !== null) {
            return self::$stamp;
        }

        $file    = self::dir() . '/stamp';
        $current = is_file($file) ? (string) @file_get_contents($file) : '';
        $current = trim($current);

        if ($current === '') {
            $current = Str::token(8);
            if (Paths::ensureWritable(self::dir())) {
                @file_put_contents($file, $current, LOCK_EX);
            }
        }

        return self::$stamp = $current;
    }

    /**
     * A cache entry, or null when there is none, it was written under an older
     * stamp, or it has simply aged out.
     *
     * @return array<string,mixed>|null
     */
    private static function read(string $name): ?array
    {
        $file = self::dir() . '/' . $name . '.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!is_array($entry)) {
            return null;
        }

        if (($entry['stamp'] ?? null) !== self::stamp()) {
            return null;
        }

        $at = isset($entry['at']) ? (int) $entry['at'] : 0;
        if ($at <= 0 || time() - $at > self::TTL) {
            return null;
        }

        return $entry;
    }

    /** @param array<string,mixed> $entry */
    private static function write(string $name, array $entry): void
    {
        $dir = self::dir();
        if (!Paths::ensureWritable($dir)) {
            return;
        }

        $entry['stamp'] = self::stamp();
        $entry['at']    = time();

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        // LOCK_EX rather than write-then-rename: rename() over an existing file
        // is not portable, and a half-written cache file only has to be
        // unreadable, not impossible - `read()` throws away anything that does
        // not decode.
        @file_put_contents($dir . '/' . $name . '.json', $json, LOCK_EX);
    }
}
