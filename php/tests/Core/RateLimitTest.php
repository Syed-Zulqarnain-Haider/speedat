<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\HttpError;
use Speedat\Core\Ip;
use Speedat\Core\RateLimit;
use Speedat\Tests\TestCase;

/**
 * The rate limiter, against a real database - the counter lives in MySQL and
 * the interesting behaviour (the atomic upsert, the window boundary) only
 * exists there.
 *
 * Time is injected rather than waited for: `nowMs` is what lets a test step
 * from one window into the next without a sleep, and what would otherwise
 * make this the slowest test in the suite.
 */
final class RateLimitTest extends TestCase
{
    /**
     * A fixed instant that is EXACTLY on a 60-second boundary
     * (1700000040 / 60 = 28333334, no remainder).
     *
     * The alignment is the point. Windows are floor(now / window) * window, so
     * a "round-looking" timestamp like 1700000000000 is actually 20 seconds
     * into its window and every expectation written from it is off by twenty.
     */
    private const WINDOW_START_MS = 1_700_000_040_000;

    /** Unique per run so a leftover row from an interrupted run cannot fail the next one. */
    private string $bucket = '';

    private string $ip = '';

    public function setUp(): void
    {
        $this->bucket = 'test-' . bin2hex(random_bytes(4));
        $this->ip     = '203.0.113.' . random_int(1, 254);

        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            Db::exec('DELETE FROM rate_limits WHERE k LIKE ?', [$this->bucket . ':%']);
        }
    }

    public function testAWindowCountsUpAndThenRefuses(): void
    {
        $now   = self::WINDOW_START_MS;
        $limit = 3;

        $first = RateLimit::hit($this->bucket, $this->ip, $limit, 60, $now);
        $this->assertTrue($first->ok, 'the first hit is always allowed');
        $this->assertSame(1, $first->count);
        $this->assertSame(2, $first->remaining);

        $second = RateLimit::hit($this->bucket, $this->ip, $limit, 60, $now + 1000);
        $this->assertSame(2, $second->count, 'a second hit in the same window increments');
        $this->assertSame(1, $second->remaining);

        $third = RateLimit::hit($this->bucket, $this->ip, $limit, 60, $now + 2000);
        $this->assertTrue($third->ok, 'the hit that reaches the limit is still allowed');
        $this->assertSame(0, $third->remaining);

        $fourth = RateLimit::hit($this->bucket, $this->ip, $limit, 60, $now + 3000);
        $this->assertFalse($fourth->ok, 'the hit past the limit is refused');
        $this->assertSame(4, $fourth->count);
        $this->assertSame(0, $fourth->remaining, 'remaining never goes negative');
    }

    public function testTheCounterIsActuallyInTheDatabase(): void
    {
        $now = self::WINDOW_START_MS;

        RateLimit::hit($this->bucket, $this->ip, 10, 60, $now);
        RateLimit::hit($this->bucket, $this->ip, 10, 60, $now + 500);

        $key = $this->bucket . ':' . Ip::hash($this->ip);
        $row = Db::one('SELECT k, count FROM rate_limits WHERE k = ?', [$key]);

        $this->assertNotNull($row, 'the limiter must write a row keyed by bucket and hashed ip');
        $this->assertSame(2, $row['count'] ?? null, 'and the column must come back as an int, not a string');

        // The address itself must never be in the table.
        $this->assertStringNotContains($this->ip, (string) ($row['k'] ?? ''));
    }

    public function testTheNextWindowStartsFromZero(): void
    {
        $window = 60;
        $now    = self::WINDOW_START_MS;

        RateLimit::hit($this->bucket, $this->ip, 2, $window, $now);
        $blocked = RateLimit::hit($this->bucket, $this->ip, 1, $window, $now + 1000);
        $this->assertFalse($blocked->ok, 'the window is full');

        // One whole window later: a new row, counting from one again.
        $next = RateLimit::hit($this->bucket, $this->ip, 1, $window, $now + $window * 1000);
        $this->assertTrue($next->ok, 'a new window forgets the old one');
        $this->assertSame(1, $next->count);

        $rows = Db::value('SELECT COUNT(*) FROM rate_limits WHERE k LIKE ?', [$this->bucket . ':%']);
        $this->assertSame(2, $rows, 'two windows means two rows');
    }

    public function testRetryAfterIsTheTimeLeftInTheWindow(): void
    {
        $window = 60;
        // 15 seconds into a window that starts on a boundary: 45 left.
        $now = self::WINDOW_START_MS + 15_000;

        $result = RateLimit::hit($this->bucket, $this->ip, 1, $window, $now);

        $this->assertSame(45, $result->retryAfter);
    }

    public function testDifferentBucketsAndAddressesDoNotShareACounter(): void
    {
        $now = self::WINDOW_START_MS;

        RateLimit::hit($this->bucket, $this->ip, 5, 60, $now);
        RateLimit::hit($this->bucket, $this->ip, 5, 60, $now);

        $otherIp = RateLimit::hit($this->bucket, '198.51.100.7', 5, 60, $now);
        $this->assertSame(1, $otherIp->count, 'another visitor starts from zero');

        $otherBucket = RateLimit::hit($this->bucket . '-x', $this->ip, 5, 60, $now);
        $this->assertSame(1, $otherBucket->count, 'another bucket starts from zero');

        Db::exec('DELETE FROM rate_limits WHERE k LIKE ?', [$this->bucket . '-x:%']);
    }

    /* ===================================================================== */
    /*  WHAT HAPPENS WHEN THE COUNTER IS UNREACHABLE (audit round 1, #10)     */
    /* ===================================================================== */

    /**
     * The whole finding, in one test: with the database gone, the login bucket
     * REFUSES and the quote bucket allows.
     *
     * Before the fix both allowed, so "the database blinked" meant unlimited
     * password guesses - and on this host that is not hypothetical: 50 MySQL
     * connections is the documented ceiling, so the limiter breaks under exactly
     * the load that makes an attacker's traffic hard to see.
     *
     * The database is really taken away rather than mocked: the port is pointed
     * at something that is not listening and the connection is dropped, so this
     * exercises the actual `catch (\Throwable)` with an actual PDO failure.
     */
    public function testACredentialBucketFailsClosedAndAPublicFormFailsOpen(): void
    {
        $this->withoutADatabase(function (): void {
            $login = RateLimit::hit('login', $this->ip, 10, 900, self::WINDOW_START_MS);

            $this->assertFalse($login->ok, 'login must refuse when the counter cannot be read');
            $this->assertSame(0, $login->remaining);
            $this->assertTrue($login->count > 10, 'and read as a genuinely exhausted bucket: ' . $login->count);
            $this->assertTrue($login->retryAfter > 0, 'with something to tell the visitor to wait for');

            $quotes = RateLimit::hit('quotes', $this->ip, 10, 900, self::WINDOW_START_MS);

            $this->assertTrue($quotes->ok, 'a blocked quote is a lost sale and there is no credential behind it');
        });
    }

    /**
     * The direction of the default is the point. A bucket nobody has classified
     * fails CLOSED, so the next credential endpoint is protected by the default
     * rather than by somebody remembering to add its name.
     */
    public function testAnUnknownBucketFailsClosedRatherThanOpen(): void
    {
        $this->assertFalse(RateLimit::failsOpen('login'));
        $this->assertFalse(RateLimit::failsOpen('login_account'));
        $this->assertFalse(RateLimit::failsOpen('totp'));
        $this->assertFalse(RateLimit::failsOpen('password_reset'));
        $this->assertFalse(RateLimit::failsOpen('something_nobody_has_thought_about_yet'));

        $this->assertTrue(RateLimit::failsOpen('quotes'));
        $this->assertTrue(RateLimit::failsOpen('contact'));

        $this->withoutADatabase(function (): void {
            $unknown = RateLimit::hit('some_new_admin_action', $this->ip, 5, 60, self::WINDOW_START_MS);

            $this->assertFalse($unknown->ok, 'the default is closed');
        });
    }

    /**
     * enforce() and enforceKey() both turn a refusal into the 429 the front
     * controller renders, including the fail-closed refusal - otherwise a route
     * that trusts enforce() would sail past an unreachable counter.
     */
    public function testEnforceThrowsA429WhenTheCounterIsUnreachable(): void
    {
        $this->withoutADatabase(function (): void {
            $error = $this->assertThrows(
                HttpError::class,
                static fn (): RateLimit => RateLimit::enforceKey('login_account', 'admin@example.test', 10, 900)
            );

            $this->assertSame(429, $error->status ?? 0);
            $this->assertStringContains('wait a moment', $error->getMessage(), 'and it says nothing about a database');
            $this->assertStringNotContains('database', strtolower($error->getMessage()));
        });
    }

    /**
     * The per-account counter, which did not exist: §2 asks for per-IP AND
     * per-account and only per-IP was wired up, so one attacker rotating
     * addresses against one known admin email had no limit at all.
     */
    public function testTheAccountCounterIsSeparateFromTheAddressCounter(): void
    {
        $now   = self::WINDOW_START_MS;
        $email = 'someone@example.test';

        RateLimit::hit($this->bucket, $email, 5, 60, $now);
        $second = RateLimit::hit($this->bucket, $email, 5, 60, $now);

        $this->assertSame(2, $second->count, 'two attempts on one account, whatever address they came from');

        // A different address is a different key, so the IP counter would have
        // said "one" for each of those - which is the hole.
        $byAddress = RateLimit::hit($this->bucket, $this->ip, 5, 60, $now);
        $this->assertSame(1, $byAddress->count);

        // And the account key is hashed: the email is never in the table.
        $key = $this->bucket . ':' . Ip::hash($email);
        $row = Db::one('SELECT k FROM rate_limits WHERE k = ?', [$key]);
        $this->assertNotNull($row, 'the account row is keyed by the hash');
        $this->assertStringNotContains('example.test', (string) ($row['k'] ?? ''));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run $work with the database genuinely unreachable, then put it back.
     *
     * Pointing db.port at a closed port and calling Db::reset() is what makes
     * the next Db::pdo() throw for real. The restore runs in a finally, because
     * a test that leaves the suite pointed at a dead port fails every test
     * after it and the failure message names the wrong file.
     */
    private function withoutADatabase(\Closure $work): void
    {
        $host = Config::str('db.host');
        $port = Config::int('db.port', 3306);

        Config::set('db.host', '127.0.0.1');
        Config::set('db.port', 1);     // nothing listens on port 1
        Db::reset();

        try {
            $work();
        } finally {
            Config::set('db.host', $host);
            Config::set('db.port', $port);
            Db::reset();
        }
    }
}
