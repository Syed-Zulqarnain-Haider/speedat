<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Admin sessions, kept in the database rather than in PHP's own session files.
 *
 * Shared hosting writes PHP sessions into a directory other accounts have been
 * known to read, garbage-collects them on a schedule nobody controls, and
 * offers no way to answer "who is signed in right now" or to revoke a single
 * login. A table answers all three.
 *
 * THE TOKEN IS NEVER STORED. The cookie carries 32 random bytes; the table
 * stores their SHA-256. A dump of the sessions table therefore cannot be
 * replayed as a login, the same reason password hashes exist.
 *
 * Two clocks end a session:
 *   - absolute (auth.session_lifetime): signed in this long ago, sign in again
 *   - idle (auth.session_idle): nothing for this long, sign in again
 * Both are enforced on read, so an expired row is dead the moment it is read
 * even if the sweeper has not run.
 */
final class Session
{
    public const COOKIE      = 'speedat_sid';
    public const ANON_COOKIE = 'speedat_ct';

    /** Skip the last_seen write unless this many seconds have passed - one UPDATE per page view is a waste. */
    private const TOUCH_AFTER = 60;

    /** @var array<string,mixed>|null the sessions row backing this request */
    private ?array $row = null;

    private ?string $anonSecret = null;

    private bool $loaded = false;

    public function __construct(
        private readonly Request $request,
        private readonly Cookies $cookies,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->current() !== null;
    }

    /** The signed-in admin's email address, or null. Authorisation is a separate question (Domain\Admins). */
    public function email(): ?string
    {
        $row = $this->current();
        $v   = $row['email'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** For audit lines written by a request nobody has signed in to. */
    public function actor(): string
    {
        return $this->email() ?? 'anonymous';
    }

    /**
     * Sign someone in. Any previous session for this browser is deleted rather
     * than reused, so a fixed session id planted before sign-in is worthless.
     */
    public function start(string $email): void
    {
        $this->destroy();

        $token = Str::token(32);
        $now   = Clock::utcNow();
        $ttl   = $this->lifetime();

        $row = [
            'id'         => hash('sha256', $token),
            'email'      => Str::email($email),
            'created_at' => Clock::sql($now),
            'last_seen'  => Clock::sql($now),
            'ip_hash'    => Ip::hash($this->request->ip()),
            'user_agent' => $this->request->userAgent(),
            'expires_at' => Clock::sql($now->modify('+' . $ttl . ' seconds')),
        ];

        // The form-token secret is minted with the session and lives on the
        // row rather than in the cookie - see secret(). A host that imported
        // 0001 and not 0002 has no column for it; that is a degraded site, not
        // a broken one, so the insert is retried without it rather than
        // refusing the sign-in.
        //
        // ONLY that one error is caught. `catch (\Throwable)` here meant every
        // insert failure - a duplicate key, an over-long email, a full disk -
        // was logged as "the column is missing" and retried, and the retry then
        // produced a session whose CSRF secret is the weaker derived fallback
        // that sessionSecret()'s own docblock calls "the fallback and not the
        // design". The log line named the wrong cause, so the real fault was
        // invisible. See missingCsrfSecretColumn().
        try {
            Db::insert('sessions', $row + ['csrf_secret' => Str::token(32)]);
        } catch (\PDOException $e) {
            if (!self::missingCsrfSecretColumn($e)) {
                throw $e;
            }

            Log::warn('session_csrf_secret_column_missing', ['at' => 'start']);
            Db::insert('sessions', $row);
        }

        $this->loaded = true;
        $this->row    = Db::one('SELECT * FROM sessions WHERE id = ?', [hash('sha256', $token)]);

        $this->cookies->set(self::COOKIE, $token, $ttl);
    }

    /**
     * Issue a new token for the same session, keeping the original expiry.
     * Called after anything that changes what the session can do - completing
     * the second factor, for instance - so a token captured before the change
     * does not carry the privileges granted after it.
     *
     * The CSRF secret is deliberately CARRIED ACROSS. It is a property of the
     * session, not of the cookie: a page rendered in the same request that
     * rotated (the one right after the second factor) would otherwise carry a
     * form token nobody could verify, and every open tab would fail its next
     * post. The cookie token is what rotates, and that is what an attacker who
     * captured the old one holds.
     */
    public function rotate(): void
    {
        $row = $this->current();
        if ($row === null) {
            return;
        }

        $next = Str::token(32);

        $carried = [
            'id'         => hash('sha256', $next),
            'email'      => (string) ($row['email'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? Clock::sql()),
            'last_seen'  => Clock::sql(),
            'ip_hash'    => Ip::hash($this->request->ip()),
            'user_agent' => $this->request->userAgent(),
            'expires_at' => (string) ($row['expires_at'] ?? Clock::sql()),
        ];

        if (array_key_exists('csrf_secret', $row)) {
            $carried['csrf_secret'] = (string) $row['csrf_secret'];
        }

        Db::transaction(function () use ($row, $carried): void {
            Db::insert('sessions', $carried);
            Db::exec('DELETE FROM sessions WHERE id = ?', [(string) ($row['id'] ?? '')]);
        });

        $this->row = Db::one('SELECT * FROM sessions WHERE id = ?', [hash('sha256', $next)]);

        $remaining = max(60, $this->expiresIn());
        $this->cookies->set(self::COOKIE, $next, $remaining);
    }

    /** Sign out: the row goes, so the token is dead everywhere, not just in this browser. */
    public function destroy(): void
    {
        $cookie = $this->request->cookie(self::COOKIE);
        if (is_string($cookie) && $cookie !== '') {
            try {
                Db::exec('DELETE FROM sessions WHERE id = ?', [hash('sha256', $cookie)]);
            } catch (\Throwable $e) {
                Log::exception('session_destroy_failed', $e);
            }
        }

        $this->row    = null;
        $this->loaded = true;
        $this->cookies->delete(self::COOKIE);
    }

    /** Seconds until the absolute expiry, or 0 when there is no session. */
    public function expiresIn(): int
    {
        $row = $this->current();
        $at  = Clock::fromSql(isset($row['expires_at']) && is_string($row['expires_at']) ? $row['expires_at'] : null);

        return $at === null ? 0 : max(0, $at->getTimestamp() - Clock::timestamp());
    }

    /**
     * The per-session secret CSRF tokens are bound to.
     *
     * THE ONE PROPERTY THIS MUST HAVE: for a given browser and a given
     * request, it is the same value however many times it is asked for, and
     * whatever else has happened in the request first. It used to return the
     * session cookie token when the row had already been loaded and the
     * anonymous cookie when it had not - so a form token issued before
     * anything touched the session was signed with one secret and verified
     * against another, and every admin form could fail with "this form
     * expired" for no reason but the order of two calls. That is why the first
     * line resolves the session rather than testing a lazily-set property.
     *
     * Signed in, the secret is the row's own `csrf_secret`: minted with the
     * session, carried across rotate(), and gone when the row is deleted, so
     * signing out still invalidates every form that was open. Signed out, it
     * is a random value in its own cookie, created on demand - the public
     * quote and contact forms need CSRF protection too, and giving every
     * anonymous visitor a database row to get it would be a table full of
     * nothing.
     */
    public function secret(): string
    {
        $row = $this->current();

        if ($row !== null) {
            return $this->sessionSecret($row);
        }

        if ($this->anonSecret !== null) {
            return $this->anonSecret;
        }

        $existing = $this->request->cookie(self::ANON_COOKIE);
        if (is_string($existing) && preg_match('/^[a-f0-9]{32,64}$/', $existing) === 1) {
            return $this->anonSecret = $existing;
        }

        $this->anonSecret = Str::token(16);
        $this->cookies->set(self::ANON_COOKIE, $this->anonSecret, 86400);

        return $this->anonSecret;
    }

    /**
     * The signed-in half of secret(), including the two degraded cases.
     *
     * A row with no usable `csrf_secret` is either a session that predates
     * migration 0002 or a host that never applied it. Backfilling is tried
     * once; if even that fails (the column really is not there) the secret is
     * derived from the parts of the row that rotate() carries across, so the
     * value is still the same on every call and still survives a rotation.
     * It is weaker than a random column - two sessions of the same admin
     * created in the same second would share it - and that is why it is the
     * fallback and not the design.
     *
     * @param array<string,mixed> $row
     */
    private function sessionSecret(array $row): string
    {
        $stored = is_string($row['csrf_secret'] ?? null) ? $row['csrf_secret'] : '';
        if ($stored !== '') {
            return $stored;
        }

        $fresh = Str::token(32);

        try {
            Db::exec('UPDATE sessions SET csrf_secret = ? WHERE id = ?', [$fresh, (string) ($row['id'] ?? '')]);

            if ($this->row !== null) {
                $this->row['csrf_secret'] = $fresh;
            }

            return $fresh;
        } catch (\PDOException $e) {
            // Same narrowing as start(): only "there is no such column" may take
            // the degraded path. A locked table or a read-only replica must not
            // quietly downgrade every form token on the site to the derived
            // fallback and say the schema is old.
            if (!self::missingCsrfSecretColumn($e)) {
                throw $e;
            }

            Log::warn('session_csrf_secret_column_missing', ['at' => 'secret']);
        }

        return hash_hmac(
            'sha256',
            'session|' . (string) ($row['email'] ?? '') . '|' . (string) ($row['created_at'] ?? ''),
            Config::derivedKey('csrf-session')
        );
    }

    /**
     * Is this the one error that means migration 0002 was never applied?
     *
     * 1054 is MySQL's ER_BAD_FIELD_ERROR and SQLSTATE 42S22 is its standard
     * spelling; both are checked because a driver that reports one without the
     * other still means the same thing, and getting this wrong in either
     * direction is the bug being fixed - too narrow refuses a sign-in on an
     * un-migrated host, too wide hides a real failure behind a wrong message.
     */
    private static function missingCsrfSecretColumn(\PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        if (is_int($driverCode) && $driverCode === 1054) {
            return true;
        }

        return ($e->errorInfo[0] ?? '') === '42S22';
    }

    /** Delete expired rows. Called by the cron entry point, not by page loads. */
    public static function sweep(): int
    {
        $idle = Config::int('auth.session_idle', 8 * 3600);

        return Db::exec(
            'DELETE FROM sessions WHERE expires_at < ? OR last_seen < ?',
            [Clock::sql(), Clock::sql(Clock::utcNow()->modify('-' . max(300, $idle) . ' seconds'))]
        );
    }

    /**
     * Read the cookie, validate the row, and refresh last_seen at most once a
     * minute. A database that is down means "nobody is signed in", never an
     * error page on the public site.
     *
     * @return array<string,mixed>|null
     */
    private function current(): ?array
    {
        if ($this->loaded) {
            return $this->row;
        }

        $this->loaded = true;

        $cookie = $this->request->cookie(self::COOKIE);
        if (!is_string($cookie) || preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1) {
            return null;
        }

        try {
            $row = Db::one('SELECT * FROM sessions WHERE id = ?', [hash('sha256', $cookie)]);
        } catch (\Throwable $e) {
            Log::exception('session_lookup_failed', $e);

            return null;
        }

        if ($row === null) {
            return null;
        }

        $now      = Clock::timestamp();
        $expires  = Clock::fromSql(is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null);
        $lastSeen = Clock::fromSql(is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null);
        $idle     = Config::int('auth.session_idle', 8 * 3600);

        $dead = $expires === null
            || $expires->getTimestamp() <= $now
            || $lastSeen === null
            || ($now - $lastSeen->getTimestamp()) > $idle;

        if ($dead) {
            try {
                Db::exec('DELETE FROM sessions WHERE id = ?', [(string) ($row['id'] ?? '')]);
            } catch (\Throwable) {
                // The sweeper will get it.
            }
            $this->cookies->delete(self::COOKIE);

            return null;
        }

        $this->row = $row;

        if ($lastSeen !== null && ($now - $lastSeen->getTimestamp()) > self::TOUCH_AFTER) {
            try {
                Db::exec('UPDATE sessions SET last_seen = ? WHERE id = ?', [Clock::sql(), (string) ($row['id'] ?? '')]);
            } catch (\Throwable $e) {
                Log::exception('session_touch_failed', $e);
            }
        }

        return $this->row;
    }

    private function lifetime(): int
    {
        return max(600, Config::int('auth.session_lifetime', 5 * 86400));
    }
}
