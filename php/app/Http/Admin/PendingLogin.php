<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Core\Str;

/**
 * The half-finished sign-in: the first factor passed, the six-digit code not
 * entered yet.
 *
 * THE BROWSER IS GIVEN A COOKIE AND NOTHING ELSE. The React login held the
 * Firebase ID token in the page between the two steps and posted it again with
 * the code; the port cannot and must not - a token good enough to sign in would
 * be sitting in a page the owner might leave open on the office laptop. So the
 * outcome of the first step is kept on the server and the browser holds a random
 * value that means nothing anywhere else.
 *
 * AND IT IS NOT A SESSION. `Core\Session::start()` is only ever called once both
 * factors are in: until then `Session::isAuthenticated()` is false, so there is
 * no state in which a guard could mistake a half-finished sign-in for an admin.
 * That is the reason this is a separate record rather than a flag on the session
 * row - a flag is a thing a new route can forget to check, and forgetting this
 * one would open the admin to anyone who knows a password and nothing else.
 *
 * WHERE IT IS STORED. `app_settings`, the schema's own key/value table, under
 * `plogin:<sha256 of the cookie value>`. Two consequences worth stating:
 *
 *   - THE COOKIE VALUE IS NOT IN THE TABLE, only its hash, so a dump of
 *     `app_settings` cannot be replayed as a pending login. Same reasoning as
 *     `sessions.id` and as password hashes.
 *   - a row outlives its own TTL only until the next attempt, which sweeps
 *     everything older than the TTL. There is no scheduled job to add and
 *     nothing to remember: a stale row is refused on read before it is deleted.
 *
 * "Start again" on the code screen posts to the LOGOUT route, which calls
 * abandon() - so walking away from the second step destroys the record rather
 * than leaving it to time out.
 */
final class PendingLogin
{
    public const COOKIE = 'speedat_plg';

    /** How long the owner has to read a code off his phone and type it. */
    public const TTL = 600;

    /** How the first factor was passed. Recorded so the audit line can say. */
    public const BY_PASSWORD = 'password';
    public const BY_GOOGLE   = 'google';

    private const PREFIX = 'plogin:';

    /**
     * Start one. Any previous pending login for this browser is deleted rather
     * than reused, so a value planted before the password step is worthless.
     */
    public static function begin(App $app, string $email, string $method): void
    {
        self::abandon($app);
        self::sweep();

        $token = Str::token(16);
        $key   = self::key($token);

        $value = Db::json([
            'email'  => Str::email($email),
            'method' => $method === self::BY_GOOGLE ? self::BY_GOOGLE : self::BY_PASSWORD,
            'exp'    => Clock::timestamp() + self::TTL,
        ]);

        Caps::assert('app_settings', ['k' => $key, 'updated_by' => 'login']);

        Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :at, :by)'
            . ' ON DUPLICATE KEY UPDATE v = :v2, updated_at = :at2, updated_by = :by2',
            [
                ':k'   => $key,
                ':v'   => $value,
                ':at'  => Clock::sql(),
                ':by'  => 'login',
                ':v2'  => $value,
                ':at2' => Clock::sql(),
                ':by2' => 'login',
            ],
        );

        $app->cookies->set(self::COOKIE, $token, self::TTL);
    }

    /**
     * The pending login this request carries, or null.
     *
     * Null for a missing cookie, a malformed one, a row that is not there, a
     * row that has expired, and a row whose address is no longer an admin. The
     * caller may therefore treat null as "start again" without asking why.
     *
     * @return array{email:string,method:string,id:string}|null
     */
    public static function current(App $app): ?array
    {
        $token = $app->request->cookie(self::COOKIE);

        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $key = self::key($token);

        try {
            $raw = Db::value('SELECT v FROM app_settings WHERE k = ?', [$key]);
        } catch (\Throwable $e) {
            Log::exception('pending_login_read_failed', $e);

            return null;
        }

        if ($raw === null) {
            return null;
        }

        $data  = Db::unjson($raw);
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $exp   = is_numeric($data['exp'] ?? null) ? (int) $data['exp'] : 0;

        if ($email === '' || $exp <= Clock::timestamp()) {
            self::forget($key);
            $app->cookies->delete(self::COOKIE);

            return null;
        }

        return [
            'email'  => $email,
            'method' => ($data['method'] ?? '') === self::BY_GOOGLE ? self::BY_GOOGLE : self::BY_PASSWORD,
            // The hashed key, so a caller can rate-limit the code attempts
            // against this one pending login without ever holding the cookie.
            'id'     => $key,
        ];
    }

    /** Throw it away: the code was accepted, refused for good, or abandoned. */
    public static function abandon(App $app): void
    {
        $token = $app->request->cookie(self::COOKIE);

        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
            self::forget(self::key($token));
        }

        $app->cookies->delete(self::COOKIE);
    }

    /** Delete every pending login older than the TTL. One statement, no job to schedule. */
    public static function sweep(): void
    {
        try {
            Db::exec(
                'DELETE FROM app_settings WHERE k LIKE ? AND updated_at < ?',
                [self::PREFIX . '%', Clock::sql(Clock::utcNow()->modify('-' . self::TTL . ' seconds'))],
            );
        } catch (\Throwable $e) {
            Log::exception('pending_login_sweep_failed', $e);
        }
    }

    private static function forget(string $key): void
    {
        try {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [$key]);
        } catch (\Throwable $e) {
            Log::exception('pending_login_delete_failed', $e);
        }
    }

    /** `plogin:<48 hex>` - 55 characters, inside `app_settings.k`'s 64. */
    private static function key(string $token): string
    {
        return self::PREFIX . substr(hash('sha256', $token), 0, 48);
    }
}
