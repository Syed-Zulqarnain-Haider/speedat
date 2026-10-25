<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Clock;
use Speedat\Core\Cookies;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Session;
use Speedat\Tests\TestCase;

/**
 * Admin sessions: the table, the cookie, the two clocks that end them, and the
 * CSRF secret that hangs off the row.
 *
 * Against the real database, because every interesting thing here is a row:
 * the token is never stored (its SHA-256 is), an expired row is dead the
 * moment it is read whether or not the sweeper has run, and signing out
 * deletes rather than marks.
 */
final class SessionTest extends TestCase
{
    /** Unique per run, so a leftover row from an interrupted run cannot fail the next one. */
    private string $email = '';

    private Cookies $jar;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->email = 'session-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->jar   = new Cookies(false);
    }

    public function tearDown(): void
    {
        Clock::freeze(null);

        if ($this->email !== '' && Db::healthy()) {
            Db::exec('DELETE FROM sessions WHERE email = ?', [$this->email]);
        }
    }

    public function testSigningInWritesARowAndACookieAndNeverStoresTheToken(): void
    {
        $session = $this->signIn();

        $this->assertTrue($session->isAuthenticated());
        $this->assertSame($this->email, $session->email());
        $this->assertSame($this->email, $session->actor());

        $token = $this->jar->queuedValue(Session::COOKIE);
        $this->assertNotNull($token, 'the browser is given a cookie');
        $this->assertSame(64, strlen((string) $token), '32 random bytes as hex');

        // The row is found by the HASH of the cookie, and the cookie itself
        // appears nowhere in the table. A dump of this table cannot be
        // replayed as a login, which is the entire design.
        $row = Db::one('SELECT * FROM sessions WHERE email = ?', [$this->email]);
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', (string) $token), (string) $row['id']);
        $this->assertNull(
            Db::one('SELECT id FROM sessions WHERE id = ?', [(string) $token]),
            'the raw token is not the primary key'
        );
    }

    public function testTheCookieCarriesTheFlagsThatMakeItSafe(): void
    {
        // Secure comes from the request (or from being in production); the
        // other three are not optional and cannot be opted out of, so the
        // check is that the jar has no way to express anything else.
        $overHttp  = new Cookies(false);
        $overHttps = new Cookies(true);

        $this->assertFalse($overHttp->isSecure());
        $this->assertTrue($overHttps->isSecure());

        $session = new Session(Request::synthetic('POST', '/admin/sign-in'), $overHttps);
        $session->start($this->email);

        // start() clears any previous cookie before setting the new one, so
        // the jar holds a delete and then a set, both for the session cookie.
        $queued = $overHttps->queued();
        $this->assertCount(2, $queued, 'the old cookie is cleared, then the new one is set');
        $this->assertSame([Session::COOKIE, Session::COOKIE], $overHttps->queuedNames());
        $this->assertSame('', $queued[0]['value'], 'the first is the delete');
        $this->assertTrue($queued[0]['expires'] < Clock::timestamp(), 'a delete is dated in the past');
        $this->assertTrue($queued[1]['expires'] > Clock::timestamp(), 'the real one expires in the future, not at zero');

        // HttpOnly, SameSite=Lax and Path=/ are set in one place for every
        // cookie this application writes - Cookies::flush(). If that ever
        // grows an argument, this line is the one that should stop it.
        $flush = (new \ReflectionMethod(Cookies::class, 'flush'))->getNumberOfParameters();
        $this->assertSame(0, $flush, 'flush() takes no arguments: the flags are not negotiable');
    }

    public function testSigningInAgainReplacesTheOldRowRatherThanKeepingIt(): void
    {
        $this->signIn();
        $first = (string) $this->jar->queuedValue(Session::COOKIE);

        // The same browser signs in again, carrying the first cookie.
        $jar     = new Cookies(false);
        $session = new Session(
            Request::synthetic('POST', '/admin/sign-in', [], [], [], [Session::COOKIE => $first]),
            $jar
        );
        $session->start($this->email);
        $second = (string) $jar->queuedValue(Session::COOKIE);

        $this->assertNotSame($first, $second);
        $this->assertNull(
            Db::one('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $first)]),
            'a session id planted before sign-in is worthless afterwards'
        );
        $this->assertNotNull(Db::one('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $second)]));
    }

    public function testRotationKeepsTheSessionAndTheExpiryButChangesTheId(): void
    {
        $session = $this->signIn();
        $before  = (string) $this->jar->queuedValue(Session::COOKIE);
        $row     = Db::one('SELECT * FROM sessions WHERE id = ?', [hash('sha256', $before)]);
        $this->assertNotNull($row);

        $session->rotate();
        $after = (string) $this->jar->queuedValue(Session::COOKIE);

        $this->assertNotSame($before, $after);
        $this->assertNull(Db::one('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $before)]), 'the old id is dead');

        $rotated = Db::one('SELECT * FROM sessions WHERE id = ?', [hash('sha256', $after)]);
        $this->assertNotNull($rotated);
        $this->assertSame((string) $row['expires_at'], (string) $rotated['expires_at'], 'the absolute expiry is not extended');
        $this->assertSame((string) $row['created_at'], (string) $rotated['created_at']);
        $this->assertSame(
            (string) $row['csrf_secret'],
            (string) $rotated['csrf_secret'],
            'the CSRF secret is carried across, or every open form breaks'
        );
    }

    public function testTheCsrfSecretIsMintedWithTheSessionAndIsStableWithinIt(): void
    {
        $session = $this->signIn();
        $secret  = $session->secret();

        $this->assertSame(64, strlen($secret), 'a stored 32-byte secret, not a derived stand-in');
        $this->assertSame($secret, $session->secret(), 'asking twice gives one answer');
        $this->assertNotSame(
            (string) $this->jar->queuedValue(Session::COOKIE),
            $secret,
            'the secret is not the cookie token'
        );

        // A second request from the same browser sees the same secret.
        $next = $this->reopen();
        $this->assertSame($secret, $next->secret());

        // A different session gets a different one.
        $otherJar     = new Cookies(false);
        $otherSession = new Session(Request::synthetic('POST', '/admin/sign-in'), $otherJar);
        $otherSession->start('other-' . $this->email);

        try {
            $this->assertNotSame($secret, $otherSession->secret());
        } finally {
            Db::exec('DELETE FROM sessions WHERE email = ?', ['other-' . $this->email]);
        }
    }

    public function testAnAnonymousVisitorGetsACookieBackedSecretAndNoRow(): void
    {
        $jar     = new Cookies(false);
        $session = new Session(Request::synthetic('GET', '/'), $jar);

        $secret = $session->secret();

        $this->assertSame($secret, $session->secret(), 'stable within the request');
        $this->assertSame([Session::ANON_COOKIE], $jar->queuedNames(), 'one cookie, and no session row');
        $this->assertSame($secret, $jar->queuedValue(Session::ANON_COOKIE));
        $this->assertFalse($session->isAuthenticated());

        // The next request carries that cookie and must get the same secret,
        // or the public quote form breaks between the page and the post.
        $returning = new Session(
            Request::synthetic('POST', '/quote', [], [], [], [Session::ANON_COOKIE => $secret]),
            new Cookies(false)
        );
        $this->assertSame($secret, $returning->secret());
    }

    public function testAnAnonymousCookieThatIsNotOursIsIgnored(): void
    {
        foreach (['', 'nope', str_repeat('z', 40), '<script>'] as $planted) {
            $jar     = new Cookies(false);
            $session = new Session(
                Request::synthetic('GET', '/', [], [], [], [Session::ANON_COOKIE => $planted]),
                $jar
            );

            $this->assertNotSame($planted, $session->secret(), 'a planted value is replaced, not adopted');
        }
    }

    /**
     * The ABSOLUTE clock, isolated from the idle one.
     *
     * Both are enforced on read, so a test that just waits proves whichever
     * fires first - the idle window, at eight hours. To see the absolute
     * expiry on its own, last_seen is moved forward with the frozen clock, so
     * the session is busy right up to the moment its lifetime runs out.
     */
    public function testASessionDiesAtItsAbsoluteExpiry(): void
    {
        Clock::freeze('2026-03-01 08:00:00');
        $this->signIn();
        $token = (string) $this->jar->queuedValue(Session::COOKIE);
        $id    = hash('sha256', $token);

        // Four hours later: inside both windows.
        Clock::freeze('2026-03-01 12:00:00');
        $this->assertTrue($this->open($token)->isAuthenticated());

        // Six days later, and busy the whole time (the default lifetime is
        // five days, so only the absolute clock can end this).
        Clock::freeze('2026-03-07 08:00:00');
        Db::exec('UPDATE sessions SET last_seen = ? WHERE id = ?', ['2026-03-07 07:59:00', $id]);

        $dead = $this->open($token);
        $this->assertFalse($dead->isAuthenticated(), 'past auth.session_lifetime, however active');
        $this->assertNull($dead->email());

        // And the row was deleted on read, not merely ignored.
        $this->assertNull(Db::one('SELECT id FROM sessions WHERE id = ?', [$id]));
    }

    public function testASessionDiesAfterTheIdleWindowEvenInsideItsLifetime(): void
    {
        Clock::freeze('2026-03-01 08:00:00');
        $this->signIn();
        $token = (string) $this->jar->queuedValue(Session::COOKIE);

        // Nine hours of nothing, against an 8 hour idle window - still two
        // days inside the absolute lifetime.
        Clock::freeze('2026-03-01 17:00:01');

        $this->assertFalse($this->open($token)->isAuthenticated(), 'idle for longer than auth.session_idle');
        $this->assertNull(Db::one('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $token)]));
    }

    public function testAnUnknownOrMalformedCookieIsNotASession(): void
    {
        foreach (['', 'not-hex', str_repeat('a', 63), str_repeat('a', 64), str_repeat('A', 64)] as $cookie) {
            $session = $this->open($cookie);

            $this->assertFalse($session->isAuthenticated(), 'cookie "' . substr($cookie, 0, 12) . '" is not a session');
            $this->assertSame('anonymous', $session->actor());
        }
    }

    public function testSigningOutDeletesTheRowSoTheTokenIsDeadEverywhere(): void
    {
        $this->signIn();
        $token = (string) $this->jar->queuedValue(Session::COOKIE);

        $session = $this->open($token);
        $session->destroy();

        $this->assertFalse($session->isAuthenticated());
        $this->assertNull(Db::one('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $token)]));
        $this->assertFalse($this->open($token)->isAuthenticated(), 'another browser holding the same token is out too');
    }

    public function testExpiresInCountsDownAndIsZeroWithoutASession(): void
    {
        Clock::freeze('2026-03-01 08:00:00');
        $this->signIn();
        $token = (string) $this->jar->queuedValue(Session::COOKIE);

        Clock::freeze('2026-03-01 09:00:00');
        $remaining = $this->open($token)->expiresIn();

        // Default lifetime is 5 days; an hour has gone.
        $this->assertTrue($remaining > 0 && $remaining <= 5 * 86400 - 3600, 'got ' . $remaining);
        $this->assertSame(0, $this->open('')->expiresIn(), 'no session, no countdown');
    }

    public function testTheSweeperRemovesExpiredRowsAndLeavesLiveOnes(): void
    {
        Clock::freeze('2026-03-01 08:00:00');
        $this->signIn();
        $live = hash('sha256', (string) $this->jar->queuedValue(Session::COOKIE));

        // A row that expired a week ago, written directly - this is what the
        // sweeper is for, and there is no way to age one otherwise.
        $stale = hash('sha256', 'stale-' . $this->email);
        Db::insert('sessions', [
            'id'          => $stale,
            'email'       => $this->email,
            'created_at'  => '2026-02-01 08:00:00',
            'last_seen'   => '2026-02-01 08:00:00',
            'ip_hash'     => null,
            'user_agent'  => '',
            'csrf_secret' => str_repeat('0', 64),
            'expires_at'  => '2026-02-02 08:00:00',
        ]);

        Session::sweep();

        $this->assertNull(Db::one('SELECT id FROM sessions WHERE id = ?', [$stale]), 'the expired row is gone');
        $this->assertNotNull(Db::one('SELECT id FROM sessions WHERE id = ?', [$live]), 'the live one is not');
    }

    /* ===================================================================== */
    /*  THE CATCH-ALL THAT HID REAL FAILURES (audit round 1, #14)             */
    /* ===================================================================== */

    /**
     * start() wraps its insert in a try/catch so a host that imported migration
     * 0001 and not 0002 can still sign people in. That catch used to be
     * `\Throwable`, so ANY insert failure was logged as
     * `session_csrf_secret_column_missing` and retried - and the retry produced
     * a session whose CSRF secret is the weaker derived fallback instead of a
     * random column. The log named the wrong cause, so the real fault was
     * invisible.
     *
     * The failure used here is the one the audit named: `sessions.email` is
     * VARCHAR(190), `Str::email()` does not cap, and STRICT_TRANS_TABLES makes
     * an over-long value an error (1406), not a truncation.
     *
     * WHAT THIS TEST HAS TO WATCH, AND WHY IT IS THE LOG. The exception came out
     * either way - the retry insert is outside the try and fails on the same
     * column - so "did it throw" does not distinguish the two versions. The
     * damage was the DIAGNOSIS: the old code wrote
     * `session_csrf_secret_column_missing` on its way past a data error, which
     * sends whoever reads the log to migration 0002 instead of to the field that
     * was too long. So the assertion is that the misleading line is not written,
     * which is exactly what the narrowing changed.
     */
    public function testAnInsertFailureThatIsNotTheMissingColumnIsNotMislabelled(): void
    {
        $tooLong = str_repeat('a', 200) . '@example.test';
        $session = new Session(Request::synthetic('POST', '/admin/sign-in'), new Cookies(false));

        $logWas = $this->logLength();

        $thrown = $this->assertThrows(
            \PDOException::class,
            static function () use ($session, $tooLong): void {
                $session->start($tooLong);
            },
            'an over-long email is a real failure, not an old schema'
        );

        // 1406 is ER_DATA_TOO_LONG. The point is that it is NOT 1054.
        $this->assertNotSame(1054, $thrown->errorInfo[1] ?? null, 'and not the column error either');

        $this->assertStringNotContains(
            'session_csrf_secret_column_missing',
            $this->logSince($logWas),
            'a data error must not be logged as an old schema - that is how the real fault stays invisible'
        );

        $this->assertNull(
            Db::one('SELECT id FROM sessions WHERE email = ?', [mb_strtolower($tooLong)]),
            'the failed sign-in left no row behind'
        );
    }

    /**
     * The other half: the degraded path the catch exists for still works. The
     * column is really dropped and put back, because "0002 was never applied"
     * cannot be simulated with a mock.
     *
     * A sign-in on that host succeeds, writes a row, and the CSRF secret is the
     * derived fallback - the same value on every call, which is the one property
     * Csrf cannot do without.
     */
    public function testAHostWithoutTheCsrfSecretColumnCanStillSignIn(): void
    {
        // THE DEFINITION IS TAKEN FROM THE DATABASE, not written out here.
        // Rebuilding it from information_schema's COLUMN_TYPE and IS_NULLABLE
        // lost the charset, the collation and - the one that bit - the
        // `DEFAULT ''`, so every later test that inserted a session row without
        // a csrf_secret failed with 1364 in a completely different suite. MySQL's
        // own SHOW CREATE TABLE line is the whole definition by construction.
        $create = Db::one('SHOW CREATE TABLE sessions');
        $ddl    = (string) ($create['Create Table'] ?? ($create['create table'] ?? ''));

        if (preg_match('/^[ \t]*`csrf_secret`[^\n]*/m', $ddl, $line) !== 1) {
            $this->skip('this database has no sessions.csrf_secret column to drop, so migration 0002 is not applied here');
        }

        $definition = rtrim(trim($line[0]), ',');

        // No user input reaches this DDL: it is MySQL's own text for a column
        // this test has just confirmed exists.
        Db::exec('ALTER TABLE sessions DROP COLUMN csrf_secret');
        Db::reset();

        try {
            $session = $this->signIn();

            $this->assertTrue($session->isAuthenticated(), 'an un-migrated host is degraded, not broken');
            $this->assertNotNull(Db::one('SELECT id FROM sessions WHERE email = ?', [$this->email]));

            $first = $session->secret();
            $this->assertSame(64, strlen($first), 'the derived fallback is a sha256 hex string');
            $this->assertSame($first, $session->secret(), 'and it is the same value on every call');
        } finally {
            Db::exec('ALTER TABLE sessions ADD COLUMN ' . $definition . ' AFTER `user_agent`');
            Db::reset();
        }

        // Not "is it there" but "is it the same column": a restore that lost the
        // DEFAULT would leave every later insert that omits csrf_secret failing
        // with 1364, in a suite with no connection to this file.
        $after = Db::one('SHOW CREATE TABLE sessions');
        $back  = (string) ($after['Create Table'] ?? ($after['create table'] ?? ''));

        $this->assertStringContains(
            $definition,
            $back,
            'the column is back exactly as it was, or every test after this one is testing a different schema'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Today's log file, as a byte offset.
     *
     * Zero when there is no file, which makes logSince() read "" and the
     * assertion above trivially true. That is deliberate: on a host where the
     * logger cannot write, this test simply stops discriminating rather than
     * failing for a reason that has nothing to do with sessions.
     */
    private function logLength(): int
    {
        $file = $this->logFile();

        return is_file($file) ? (int) filesize($file) : 0;
    }

    private function logSince(int $offset): string
    {
        $file = $this->logFile();
        if (!is_file($file)) {
            return '';
        }

        $text = (string) @file_get_contents($file);

        return $offset < strlen($text) ? substr($text, $offset) : '';
    }

    private function logFile(): string
    {
        return Paths::logs() . '/app-' . Clock::utcNow()->format('Y-m-d') . '.log';
    }

    private function signIn(): Session
    {
        $this->jar = new Cookies(false);

        $session = new Session(Request::synthetic('POST', '/admin/sign-in'), $this->jar);
        $session->start($this->email);

        return $session;
    }

    /** A later request from a browser holding $token. */
    private function open(string $token): Session
    {
        return new Session(
            Request::synthetic('GET', '/admin', [], [], [], [Session::COOKIE => $token]),
            new Cookies(false)
        );
    }

    private function reopen(): Session
    {
        return $this->open((string) $this->jar->queuedValue(Session::COOKIE));
    }
}
