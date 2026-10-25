<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Core\Str;
use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Password;
use Speedat\Domain\Auth\SecretBox;
use Speedat\Domain\Auth\Totp;

/**
 * The `admins` table: the only file that reads or writes it.
 *
 * WHY IT LIVES UNDER `Http\Admin` AND NOT IN `Domain`. `app/Domain/Admins/` is
 * an empty directory in another track's tree, and a sign-in that cannot ship
 * until somebody else's constructor settles is a sign-in nobody can review. The
 * rules that matter are here rather than spread across the controllers:
 * every statement is prepared, every write goes through `Caps::assert()` (the
 * schema's widths, mirrored once, in `Core\Caps`), and the two facts a login
 * needs - "is this address an admin" and "has this admin a second factor" - are
 * answered by one row read in one place. If `Domain\Admins` is ever written,
 * this is the file it replaces, and the controllers change by one import.
 *
 * THE TOTP SECRET IS NEVER STORED IN THE CLEAR. It is AES-256-GCM sealed by
 * `Domain\Auth\SecretBox` and bound to the owner's address, which is the
 * table's PRIMARY KEY - so a row copied into another admin's row will not
 * decrypt. `totp_enabled` is a SEPARATE column from `totp_secret_enc` on
 * purpose: enrolment stores the secret with the flag still off, so the QR can
 * be redrawn for the second post of a two-step form without a session-scoped
 * scratch area, and an enrolment that is abandoned leaves a secret nothing
 * accepts.
 *
 * `totp_last_step` is the replay guard. It is spent by an UPDATE that only
 * succeeds if it touches a row (see spendStep()), because two simultaneous
 * posts of the same six digits must not both be allowed.
 */
final class Admins
{
    /** The columns every read needs. `password_hash` and the secret are only read where they are used. */
    private const COLUMNS = 'email, name, role, created_at, added_by, password_hash, totp_secret_enc, totp_enabled, totp_last_step';

    /** The security page lists admins; a site with more than this has a different problem. */
    private const LIST_LIMIT = 50;

    /**
     * The signed-in admin, or null when that address is not in the table.
     *
     * Null is the answer for a missing row, a malformed address AND an
     * unreachable database. A session whose admin row cannot be confirmed is
     * not an admin - the failure direction that locks the owner out for a
     * minute rather than opening the editor to a session nobody can vouch for.
     */
    public static function find(string $email): ?AdminUser
    {
        $row = self::row($email);

        return $row === null ? null : self::user($row);
    }

    /**
     * One row, as read. Callers that need the password hash or the sealed TOTP
     * secret use this; everything else uses find().
     *
     * @return array<string,mixed>|null
     */
    public static function row(string $email): ?array
    {
        $clean = Str::email($email);

        if ($clean === '' || !Str::isEmail($clean) || !Caps::fits('admins.email', $clean)) {
            return null;
        }

        try {
            return Db::one('SELECT ' . self::COLUMNS . ' FROM admins WHERE email = ?', [$clean]);
        } catch (\Throwable $e) {
            Log::exception('admin_lookup_failed', $e, ['email' => Str::maskEmail($clean)]);

            return null;
        }
    }

    /** @param array<string,mixed> $row */
    public static function user(array $row): AdminUser
    {
        return new AdminUser(
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            role: ($row['role'] ?? '') === AdminUser::OWNER ? AdminUser::OWNER : AdminUser::EDITOR,
            totpEnabled: (int) ($row['totp_enabled'] ?? 0) === 1,
        );
    }

    /**
     * Everybody who can sign in, as the Security page lists them.
     *
     * @return list<array{email:string,role:string,totp:bool,addedAt:string}>
     */
    public static function all(): array
    {
        try {
            $rows = Db::all(
                'SELECT email, role, totp_enabled, created_at FROM admins ORDER BY created_at ASC, email ASC LIMIT ?',
                [self::LIST_LIMIT],
            );
        } catch (\Throwable $e) {
            Log::exception('admin_list_failed', $e);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'email'   => (string) ($row['email'] ?? ''),
                'role'    => ($row['role'] ?? '') === AdminUser::OWNER ? AdminUser::OWNER : AdminUser::EDITOR,
                'totp'    => (int) ($row['totp_enabled'] ?? 0) === 1,
                'addedAt' => Iso::of($row['created_at'] ?? null),
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------ password */

    /**
     * Check a password against the row, and quietly upgrade the hash when the
     * host has gained a better algorithm since it was written.
     *
     * The verify happens even when the row has no password at all
     * (`Password::verify` burns the same time on a null hash), so the clock
     * cannot be used to find out which admins have password sign-in enabled.
     *
     * @param array<string,mixed> $row
     */
    public static function passwordMatches(array $row, string $plain): bool
    {
        $hash = is_string($row['password_hash'] ?? null) ? $row['password_hash'] : null;

        if (!Password::verify($plain, $hash)) {
            return false;
        }

        // $hash is a non-empty string by now: verify() returns false for null.
        if (is_string($hash) && Password::needsRehash($hash)) {
            self::rehash((string) ($row['email'] ?? ''), $plain);
        }

        return true;
    }

    /** Write a fresh hash for a password that has just been proved correct. */
    private static function rehash(string $email, string $plain): void
    {
        try {
            $fresh = Password::hash($plain);

            Caps::assert('admins', ['email' => $email, 'password_hash' => $fresh]);
            Db::exec('UPDATE admins SET password_hash = ? WHERE email = ?', [$fresh, $email]);

            Log::info('admin_password_rehashed', ['email' => Str::maskEmail($email), 'algorithm' => Password::algorithm()]);
        } catch (\Throwable $e) {
            // The sign-in has already succeeded. A failed upgrade is a note in
            // the log, never a refusal.
            Log::exception('admin_password_rehash_failed', $e, ['email' => Str::maskEmail($email)]);
        }
    }

    /* ---------------------------------------------------------------- TOTP */

    /** The Totp calculator this application uses everywhere. */
    public static function totp(): Totp
    {
        return new Totp();
    }

    /**
     * The sealed-secret box, keyed on the application key.
     *
     * `app.retired_keys` is read so that a key rotation can still open rows
     * written under the previous one; it is absent from the sample config and
     * empty by default, which is the same as not having rotated.
     */
    public static function box(): SecretBox
    {
        $retired = [];
        foreach (Config::arr('app.retired_keys') as $key) {
            if (is_string($key) && trim($key) !== '') {
                $retired[] = $key;
            }
        }

        return new SecretBox(Config::need('app.key'), $retired);
    }

    /**
     * The admin's TOTP secret in the clear, or "" when there is none stored or
     * it cannot be opened with the keys this deployment holds.
     *
     * @param array<string,mixed> $row
     */
    public static function secret(array $row): string
    {
        $stored = is_string($row['totp_secret_enc'] ?? null) ? $row['totp_secret_enc'] : '';
        $email  = is_string($row['email'] ?? null) ? $row['email'] : '';

        if ($stored === '') {
            return '';
        }

        try {
            return self::box()->decrypt($stored, $email);
        } catch (AuthError | \Throwable $e) {
            Log::exception('admin_totp_secret_unreadable', $e, ['email' => Str::maskEmail($email)]);

            return '';
        }
    }

    /**
     * Store a fresh, UNCONFIRMED secret for an enrolment.
     *
     * It refuses point blank when the flag is already on: overwriting an
     * enabled second factor would be a way to replace the owner's
     * authenticator without ever proving you hold the old one.
     *
     * @throws AuthError when this admin already has 2FA switched on
     */
    public static function beginEnrolment(string $email, string $secret): void
    {
        $row = self::row($email);

        if ($row === null) {
            throw new AuthError('No such admin', 'That account no longer exists.');
        }

        if ((int) ($row['totp_enabled'] ?? 0) === 1) {
            throw new AuthError(
                'Refusing to re-enrol an enabled second factor',
                'Two-factor sign-in is already on for this account. Turn it off with a current code first.',
            );
        }

        $sealed = self::box()->encrypt($secret, (string) $row['email']);

        Caps::assert('admins', ['email' => (string) $row['email'], 'totp_secret_enc' => $sealed]);

        Db::exec(
            'UPDATE admins SET totp_secret_enc = ?, totp_enabled = 0, totp_last_step = NULL WHERE email = ?',
            [$sealed, (string) $row['email']],
        );
    }

    /** Switch the confirmed second factor on. Only ever called after a code verified against it. */
    public static function enableTotp(string $email, int $step): void
    {
        Db::exec(
            'UPDATE admins SET totp_enabled = 1, totp_last_step = ? WHERE email = ? AND totp_secret_enc IS NOT NULL',
            [$step, Str::email($email)],
        );
    }

    /** Switch it off and forget the secret, so turning it on again is a fresh enrolment. */
    public static function disableTotp(string $email): void
    {
        Db::exec(
            'UPDATE admins SET totp_enabled = 0, totp_secret_enc = NULL, totp_last_step = NULL WHERE email = ?',
            [Str::email($email)],
        );
    }

    /**
     * Spend a time step, or refuse it.
     *
     * THE WHOLE GUARD IS IN THE WHERE CLAUSE. A six-digit code is valid for up
     * to ninety seconds, so anyone who reads it over the owner's shoulder can
     * use it again inside that window - unless the step it belongs to has
     * already been spent. Checking the column and then writing it would leave a
     * gap two simultaneous requests both fit through; this is one statement, and
     * it either touches a row or it does not.
     *
     * `Db::exec()` returns the affected row count, and MySQL counts a row as
     * affected only when the UPDATE actually changed it - which is why the
     * clause is `<` and not `<=`, and why re-posting the same code answers
     * "already spent" rather than "fine".
     */
    public static function spendStep(string $email, int $step): bool
    {
        $affected = Db::exec(
            'UPDATE admins SET totp_last_step = :step
              WHERE email = :email AND (totp_last_step IS NULL OR totp_last_step < :step2)',
            [':step' => $step, ':email' => Str::email($email), ':step2' => $step],
        );

        return $affected === 1;
    }

    /**
     * Which time step a typed code belongs to, or null when it is no good.
     *
     * Reads only: the caller decides what spending it means. A code for a step
     * this account has already spent is null here too, so a replayed code is
     * refused before any write is attempted.
     *
     * @param array<string,mixed> $row
     */
    public static function checkCode(array $row, ?string $typed): ?int
    {
        $secret = self::secret($row);

        if ($secret === '') {
            return null;
        }

        $last = isset($row['totp_last_step']) && is_numeric($row['totp_last_step'])
            ? (int) $row['totp_last_step']
            : null;

        return self::totp()->verify($secret, $typed, $last);
    }

    /**
     * Verify a typed code against this admin's secret and spend it.
     *
     * Two things have to be true and both are checked here so no caller can do
     * half of it: the code matches a step inside the window that is newer than
     * the last one this account spent, and the UPDATE that records it touched a
     * row.
     *
     * @param array<string,mixed> $row
     */
    public static function verifyCode(array $row, ?string $typed): bool
    {
        $step = self::checkCode($row, $typed);

        if ($step === null) {
            return false;
        }

        return self::spendStep((string) ($row['email'] ?? ''), $step);
    }

    /**
     * The `otpauth://` URI and the QR for an enrolment.
     *
     * The PNG is null when the host has no gd - Hostinger lets an account
     * switch it off - and the template then tells the owner to type the key in
     * by hand, which every authenticator app accepts. A broken image would have
     * been the alternative.
     *
     * @return array{uri:string,qrDataUrl:string,secret:string}
     */
    public static function enrolmentView(string $email, string $secret): array
    {
        $uri = self::totp()->uri($secret, $email);
        $qr  = \Speedat\Domain\Auth\Qr::tryEncode($uri);

        return [
            'uri'       => $uri,
            'qrDataUrl' => $qr === null ? '' : (string) ($qr->toPngDataUri() ?? ''),
            'secret'    => Totp::readable($secret),
        ];
    }

    /** The key as the authenticator stores it: no spaces, whatever the page printed. */
    public static function normaliseSecret(string $printed): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $printed));
    }

    /** How many people can sign in at all. A zero here is a site nobody can edit. */
    public static function count(): int
    {
        try {
            return (int) Db::value('SELECT COUNT(*) FROM admins', [], 0);
        } catch (\Throwable $e) {
            Log::exception('admin_count_failed', $e);

            return 0;
        }
    }

    /**
     * Is there anybody in the table at all - and FAIL CLOSED when that cannot
     * be answered.
     *
     * count() answers 0 on an unreachable database, which is right where it is
     * used (the sign-in page warning: "nobody can sign in yet" is true when the
     * database is down, too). It is exactly wrong for `Http\Admin\SetupController`,
     * whose whole existence is conditional on this being false: a database blip
     * would otherwise make the one-time first-run page appear again on a live
     * site. So this is the strict question - "prove to me the table is empty" -
     * and anything it cannot prove is answered "somebody is there".
     */
    public static function anyExist(): bool
    {
        try {
            return Db::value('SELECT 1 FROM admins LIMIT 1') !== null;
        } catch (\Throwable $e) {
            Log::exception('admin_exists_check_failed', $e);

            // Not a guess. The only caller uses this to decide whether to open
            // a door, and a door that opens when the lock cannot be read is not
            // a lock.
            return true;
        }
    }

    /**
     * Create the very first owner, and ONLY if the table is still empty.
     *
     * THE CONDITION IS IN THE STATEMENT, and that is the whole point of this
     * method existing rather than an insert in the controller. "Check the table
     * is empty, then insert" is two statements with a gap between them, and two
     * requests that arrive together both find it empty and both insert - which
     * on a first-run setup page means a stranger who guesses the token in the
     * same second as the owner gets an account of their own. `WHERE NOT EXISTS`
     * makes the emptiness part of the write: MariaDB evaluates it against the
     * table as it is at that moment, and exactly one of two racing statements
     * can affect a row.
     *
     * Returns true when this call is the one that created the owner. False means
     * somebody else already had: no row was written, and the caller must not
     * report success.
     *
     * @param string|null $passwordHash null for a Google-only owner - an account
     *                                  with no password is not an account with a
     *                                  blank one, and `Password::verify()`
     *                                  refuses a null hash
     */
    public static function createFirstOwner(string $email, string $name, ?string $passwordHash): bool
    {
        $clean = Str::email($email);

        if ($clean === '' || !Str::isEmail($clean)) {
            return false;
        }

        $row = [
            'email'         => $clean,
            'name'          => Str::limit($name !== '' ? $name : $clean, 190),
            'role'          => AdminUser::OWNER,
            'added_by'      => 'setup',
            'created_at'    => Clock::sql(),
            'password_hash' => $passwordHash,
        ];

        // The same widths the schema has, asserted before the statement rather
        // than discovered by a truncation warning on the live host.
        Caps::assert('admins', array_filter($row, is_string(...)));

        $affected = Db::exec(
            'INSERT INTO admins (email, name, role, added_by, created_at, password_hash)
             SELECT :email, :name, :role, :added_by, :created_at, :password_hash
               FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM admins AS occupied)',
            [
                ':email'         => $row['email'],
                ':name'          => $row['name'],
                ':role'          => $row['role'],
                ':added_by'      => $row['added_by'],
                ':created_at'    => $row['created_at'],
                ':password_hash' => $passwordHash,
            ],
        );

        if ($affected !== 1) {
            return false;
        }

        // Belt and braces, and cheap. rowCount() is the driver's word for what
        // the statement did; this is the table's. If anything at all put a
        // second row in beside ours, the one we created goes away again - the
        // setup page's promise is "exactly one owner, or nothing", and a
        // half-kept promise here is an uninvited administrator.
        if (self::count() !== 1) {
            Db::exec('DELETE FROM admins WHERE email = ? AND added_by = ?', [$clean, 'setup']);

            Log::warn('setup_first_owner_rolled_back', ['email' => Str::maskEmail($clean)]);

            return false;
        }

        return true;
    }
}
