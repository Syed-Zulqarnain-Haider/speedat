<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Per-IP limits, in MySQL. The port of src/lib/limits.ts.
 *
 * Fixed windows, not a rolling average: one row per (bucket, hashed ip,
 * window start), incremented atomically. A fixed window can let through up to
 * twice the limit across a boundary, which for "stop one machine hammering the
 * quote form" is a trade nobody will ever notice, and it costs one indexed
 * upsert instead of a table scan.
 *
 * The count is read back with MySQL's LAST_INSERT_ID(expr) trick, which makes
 * increment-and-read a single statement on a single connection - a separate
 * SELECT could interleave with another request and under-count.
 *
 * ---------------------------------------------------------------------------
 * WHAT HAPPENS WHEN THE COUNTER ITSELF IS UNREACHABLE
 * ---------------------------------------------------------------------------
 * It used to fail OPEN everywhere, which is what src/lib/limits.ts does, with
 * the reasoning that "a rate limiter that takes the site down when it breaks
 * has done more damage than the traffic it was meant to shape". That reasoning
 * is right for a public form and wrong for a password field, and the login
 * bucket had it.
 *
 * The failure is not hypothetical on this host: docs/hostinger-constraints.md
 * §4 puts the ceiling at 50 MySQL connections and calls it "the tightest
 * number". Under connection pressure Db::pdo() throws, hit() used to answer
 * ok = true, and the login endpoint had no limit at all - exactly when the site
 * is under load, which is also exactly when somebody is hammering it.
 *
 * SO THE DEFAULT IS NOW CLOSED, and FAIL_OPEN below is a short, named
 * allow-list. That direction matters more than the list: a bucket somebody adds
 * next month for a credential endpoint fails closed because nobody remembered
 * it, instead of failing open because nobody remembered it. The two entries on
 * the list are the two public lead-capture forms, where a visitor turned away
 * by a database hiccup is a lost customer and there is no credential behind the
 * form to guess.
 *
 * A closed bucket answers 429 with HttpError::tooManyRequests()'s wording -
 * "Too many requests. Please wait a moment and try again." It says nothing
 * about a database, because a visitor can do nothing about a database.
 */
final class RateLimit
{
    /** Chance of sweeping old rows on any given call. */
    private const SWEEP_ODDS = 0.02;

    /** Rows older than this are of no further interest. */
    private const SWEEP_AGE = 86400;

    /**
     * The ONLY buckets that let a request through when the counter cannot be
     * reached. Everything else - and that means every bucket in front of a
     * password, a one-time code or a reset link - refuses instead.
     *
     * `quotes`  the public quote API. A blocked quote is a lost sale, the page
     *           is anonymous, and there is nothing behind it to guess.
     * `contact` the public contact form, for the same reason. Its own spam
     *           defences (honeypot, CSRF, Caps) do not depend on this counter.
     *
     * Add nothing here without a sentence saying why a locked-out visitor is
     * worse than an unlimited one.
     *
     * @var list<string>
     */
    private const FAIL_OPEN = ['quotes', 'contact'];

    public function __construct(
        public readonly bool $ok,
        public readonly int $count,
        public readonly int $remaining,
        /** Seconds until this window resets. */
        public readonly int $retryAfter,
    ) {
    }

    /**
     * Count one hit against `bucket` for `ip`.
     *
     * `$ip` is hashed with Ip::hash(), which is a keyed hash of an arbitrary
     * string and does not care what it is given - so the same method counts per
     * account when it is handed an email address. See enforceKey().
     *
     * @param int|null $nowMs Override the clock. Tests only - it is what lets
     *                        a test walk from one window into the next without
     *                        sleeping through it.
     */
    public static function hit(string $bucket, string $ip, int $limit, int $windowSec, ?int $nowMs = null): self
    {
        $windowSec = max(1, $windowSec);
        $limit     = max(1, $limit);
        $nowMs   ??= Clock::millis();

        $key           = $bucket . ':' . Ip::hash($ip);
        $windowStartMs = intdiv($nowMs, $windowSec * 1000) * $windowSec * 1000;
        $retryAfter    = (int) ceil(($windowStartMs + $windowSec * 1000 - $nowMs) / 1000);
        $windowStart   = Clock::sql((new \DateTimeImmutable('@' . intdiv($windowStartMs, 1000))));

        try {
            $stmt = Db::run(
                'INSERT INTO rate_limits (k, window_start, count) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)',
                [$key, $windowStart]
            );

            // rowCount() is 1 for a fresh insert and 2 for an update; only the
            // update path has a meaningful LAST_INSERT_ID.
            $count = $stmt->rowCount() === 1
                ? 1
                : (int) Db::pdo()->lastInsertId();

            if ($count < 1) {
                $raw   = Db::value('SELECT count FROM rate_limits WHERE k = ? AND window_start = ?', [$key, $windowStart], 1);
                $count = is_numeric($raw) ? (int) $raw : 1;
            }

            self::maybeSweep($nowMs);

            return new self($count <= $limit, $count, max(0, $limit - $count), $retryAfter);
        } catch (\Throwable $e) {
            $open = self::failsOpen($bucket);

            Log::exception('rate_limit_unavailable', $e, ['bucket' => $bucket, 'failedOpen' => $open]);

            // Closed: count reads as "over the limit" so every caller that only
            // looks at ->ok, ->remaining or ->count behaves as it would for a
            // genuinely exhausted bucket. There is no half-open state to get
            // wrong.
            return $open
                ? new self(true, 0, $limit, $retryAfter)
                : new self(false, $limit + 1, 0, $retryAfter);
        }
    }

    /**
     * Count a hit and throw 429 when the bucket is empty.
     *
     * @throws HttpError
     */
    public static function enforce(string $bucket, Request $request, int $limit, int $windowSec): self
    {
        $result = self::hit($bucket, $request->ip(), $limit, $windowSec);

        if (!$result->ok) {
            Log::warn('rate_limited', ['bucket' => $bucket, 'ip' => Ip::hash($request->ip()), 'count' => $result->count]);

            throw HttpError::tooManyRequests($result->retryAfter, ['bucket' => $bucket]);
        }

        return $result;
    }

    /**
     * enforce(), keyed on something that is not an address.
     *
     * WHY THIS EXISTS. docs/quality-bar.md §2 asks for per-IP AND per-account
     * limits, and only the per-IP half was wired up: one attacker rotating
     * addresses against one known admin email had no limit at all, because every
     * request landed in a different counter. The primitive was already here -
     * Ip::hash() is a keyed hash of any string - so this is the missing call
     * site, not a missing mechanism.
     *
     * A login route wants BOTH, in this order:
     *
     *     RateLimit::enforce('login', $request, 10, 900);
     *     RateLimit::enforceKey('login_account', Str::email($email), 10, 900);
     *
     * `rate_limits.k` is VARCHAR(160) and the stored key is
     * bucket + ':' + 32 hex, so a long bucket name is the only way to overflow
     * it and Caps guards the column.
     *
     * The address is NOT logged here: the key is an account, and writing it into
     * a field called `ip` is how a log stops meaning what it says.
     *
     * @param string $key what to count against - an email address, an admin id
     * @throws HttpError
     */
    public static function enforceKey(string $bucket, string $key, int $limit, int $windowSec): self
    {
        $result = self::hit($bucket, $key, $limit, $windowSec);

        if (!$result->ok) {
            Log::warn('rate_limited', ['bucket' => $bucket, 'keyHash' => Ip::hash($key), 'count' => $result->count]);

            throw HttpError::tooManyRequests($result->retryAfter, ['bucket' => $bucket]);
        }

        return $result;
    }

    /**
     * Does this bucket let a request through when the counter is unreachable?
     *
     * Public because a route may want to say so in its own diagnostics, and
     * because a test that asserts the list is a test somebody can read.
     */
    public static function failsOpen(string $bucket): bool
    {
        return in_array($bucket, self::FAIL_OPEN, true);
    }

    /**
     * Delete windows nobody will ask about again. Also called from bin/cron.php.
     *
     * @param int|null $nowMs The same clock the counting used. It has to be the
     *                        same one: sweeping against the wall clock while
     *                        counting against an injected one deletes the rows
     *                        that were just written, which is exactly what
     *                        happened the first time this was written.
     */
    public static function sweep(?int $nowMs = null): int
    {
        $now = ($nowMs ?? Clock::millis()) - self::SWEEP_AGE * 1000;

        try {
            return Db::exec(
                'DELETE FROM rate_limits WHERE window_start < ?',
                [Clock::sql(new \DateTimeImmutable('@' . intdiv($now, 1000)))]
            );
        } catch (\Throwable $e) {
            Log::exception('rate_limit_sweep_failed', $e);

            return 0;
        }
    }

    /** One request in fifty pays for the cleanup, so no request waits for a cron job. */
    private static function maybeSweep(?int $nowMs): void
    {
        if (random_int(1, 1000) <= (int) (self::SWEEP_ODDS * 1000)) {
            self::sweep($nowMs);
        }
    }
}
