<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use SensitiveParameter;

/**
 * Hashing and checking the owner's password.
 *
 * The Next.js app had no password of its own - Firebase held it - and the
 * `admins.password_hash` column exists in the PHP schema for one reason the
 * migration spells out: a way back in when Google is unreachable. That makes
 * this the single credential the app itself is responsible for, so it is hashed
 * with argon2id where the host has it and bcrypt at cost 12 where it does not.
 *
 * WHY THE FALLBACK IS NOT A CHOICE ANYONE MAKES AT RUNTIME. Shared hosting is
 * built from whatever PHP the provider compiled; argon2 is present on most
 * Hostinger images and absent on some. password_hash() records the algorithm
 * inside the hash, and password_verify() reads it back, so a host that gains
 * argon2 later keeps verifying its old bcrypt hashes and quietly upgrades each
 * one on the next successful sign-in (that is what needsRehash() is for).
 *
 * BCRYPT'S 72-BYTE LIMIT IS HANDLED, NOT IGNORED. bcrypt silently truncates,
 * which would mean a 100-character passphrase and its first 72 characters open
 * the same account. Rather than pre-hash (which hides the limit and breaks
 * portability), a too-long password is refused with a message that says so.
 */
final class Password
{
    /** bcrypt's own limit; only enforced when bcrypt is what we have. */
    public const BCRYPT_MAX_BYTES = 72;

    private const BCRYPT_COST = 12;

    /**
     * argon2id parameters. The defaults PHP ships (64 MiB, 4 passes) are too
     * heavy for a 128 MB shared host serving other requests at the same time;
     * 32 MiB with two passes still costs an attacker far more per guess than
     * bcrypt at cost 12, and leaves the login under a second.
     */
    private const ARGON_OPTIONS = [
        'memory_cost' => 32768,
        'time_cost'   => 2,
        'threads'     => 1,
    ];

    /** True when this host can do argon2id. */
    public static function hasArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
    }

    /** Which algorithm a new hash will use - for the admin's "security" page and the logs. */
    public static function algorithm(): string
    {
        return self::hasArgon2id() ? 'argon2id' : 'bcrypt';
    }

    /** The longest password this host can hash without silently losing characters. */
    public static function maxLength(): int
    {
        return self::hasArgon2id() ? PasswordPolicy::MAX_LENGTH : self::BCRYPT_MAX_BYTES;
    }

    /**
     * @throws AuthError when the password is empty, or too long for bcrypt to
     *                   hash without truncation
     */
    public static function hash(#[SensitiveParameter] string $plain): string
    {
        if ($plain === '') {
            throw new AuthError('Refusing to hash an empty password', 'Enter a password');
        }

        if (!self::hasArgon2id() && strlen($plain) > self::BCRYPT_MAX_BYTES) {
            throw new AuthError(
                'Password is longer than bcrypt can hash on this host',
                'This server can only take a password of up to ' . self::BCRYPT_MAX_BYTES . ' characters',
            );
        }

        $hash = self::hasArgon2id()
            ? password_hash($plain, PASSWORD_ARGON2ID, self::ARGON_OPTIONS)
            : password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        // password_hash() returns a string on success in PHP 8; a false here
        // would mean the algorithm vanished mid-request. Fail closed anyway.
        if (!is_string($hash) || $hash === '') {
            throw new AuthError('Hashing the password failed');
        }

        return $hash;
    }

    /**
     * Check a password against a stored hash.
     *
     * Constant time in the sense that matters: password_verify() compares with
     * a timing-safe comparison, and a missing hash still pays for a full hash
     * computation, so "this account has no password set" and "this password is
     * wrong" take the same time. Without that, the response time alone would
     * enumerate which admins have password sign-in enabled.
     */
    public static function verify(#[SensitiveParameter] string $plain, ?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '') {
            self::burnTime();

            return false;
        }

        return password_verify($plain, $storedHash);
    }

    /**
     * Should this hash be replaced? Call it after a SUCCESSFUL verify, with the
     * plain password still in hand, and write the new hash if it says yes.
     */
    public static function needsRehash(string $storedHash): bool
    {
        if ($storedHash === '') {
            return true;
        }

        return self::hasArgon2id()
            ? password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::ARGON_OPTIONS)
            : password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * Spend roughly what a real verify spends, so an account with no password
     * is indistinguishable from a wrong password by the clock.
     */
    private static function burnTime(): void
    {
        /**
         * Real hashes of the public string "speedat-timing-decoy", generated
         * with this class's own parameters. They are NOT credentials: nothing
         * anywhere accepts this password, and both values are in the
         * repository on purpose. A made-up string would not do - PHP rejects a
         * malformed hash immediately and the timing gap would reopen.
         */
        static $bcrypt = '$2y$12$XdiACauWTocmzZQCk4MNOOBHdix8xBfq9s1i.OJOs3g6cWU8YJ6sa';
        static $argon  = '$argon2id$v=19$m=32768,t=2,p=1$NlpyTE1SWEtNcGlIMUUvZg$ckDmpulTTm/xBgORRNX80CTteIK+ZzuzIKtVRXJMKa4';

        password_verify('speedat-timing-decoy', self::hasArgon2id() ? $argon : $bcrypt);
    }

    /** Only for the test that proves the decoys above are real hashes of that string. */
    public static function timingDecoyIsUsable(): bool
    {
        $before = hrtime(true);
        self::burnTime();

        // A rejected (malformed) hash returns in microseconds; a real one at
        // these parameters cannot. 5 ms is far below the real cost and far
        // above the cost of a refusal, so this is not a flaky clock test.
        return (hrtime(true) - $before) > 5_000_000;
    }
}
