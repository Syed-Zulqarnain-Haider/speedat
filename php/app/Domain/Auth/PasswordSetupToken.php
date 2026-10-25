<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use SensitiveParameter;

/**
 * The "set your password" link the owner gets the first time - and the only
 * way a password is ever set without knowing the old one.
 *
 * SHAPE: 32 random bytes, handed out as 64 hex characters in a URL, stored only
 * as its SHA-256. A dump of the table therefore cannot be replayed as a link,
 * for the same reason password hashes exist - and this is the same trick the
 * `sessions` table plays with the session cookie.
 *
 * WHY SHA-256 AND NOT ARGON2 HERE. A password is guessable, so it needs a slow
 * hash. This token is 256 bits from random_bytes(); there is nothing to guess,
 * and a slow hash would only mean the lookup cannot be an indexed WHERE. Fast
 * hash, timing-safe comparison, done.
 *
 * SINGLE USE AND SIXTY MINUTES. Both are enforced here, but the row must also
 * be spent atomically in phase 3:
 *
 *     UPDATE password_setup SET used_at = NOW()
 *      WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
 *
 * and the password is only written if that UPDATE touched one row. Checking
 * then updating in two statements is a race two tabs can win together.
 *
 * This class holds no database and no clock of its own - it takes the stored
 * row's values and says yes or no. Phase 3 wires it to a table.
 */
final class PasswordSetupToken
{
    /** Sixty minutes, as asked. Long enough to read an email, short enough to matter. */
    public const TTL_SECONDS = 3600;

    /** One wording for every refusal. See PasswordSetupStatus. */
    public const REFUSAL = 'That link is no longer valid. Ask for a new one.';

    /**
     * @param string $plain     give this to the person, in the link, once - it is never stored
     * @param string $hash      store this
     * @param int    $expiresAt store this too, as a DATETIME
     */
    private function __construct(
        public readonly string $plain,
        public readonly string $hash,
        public readonly int $expiresAt,
    ) {
    }

    /**
     * Mint a new token.
     *
     * @param int      $ttlSeconds how long it lives
     * @param int|null $now        unix seconds; injected by tests
     */
    public static function issue(int $ttlSeconds = self::TTL_SECONDS, ?int $now = null): self
    {
        if ($ttlSeconds < 60) {
            throw new AuthError('A set-password link must live at least a minute');
        }

        $plain = bin2hex(random_bytes(32));

        return new self($plain, self::hashOf($plain), ($now ?? time()) + $ttlSeconds);
    }

    /** How the token is stored and looked up. */
    public static function hashOf(#[SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Is the token this person presented the one in this row, still alive and
     * still unspent?
     *
     * @param string      $presented  what came out of the URL
     * @param string|null $storedHash the row's token_hash, or null when no row matched
     * @param int         $expiresAt  the row's expires_at as unix seconds
     * @param int|null    $usedAt     the row's used_at as unix seconds, or null if unspent
     */
    public static function check(
        #[SensitiveParameter] string $presented,
        ?string $storedHash,
        int $expiresAt,
        ?int $usedAt = null,
        ?int $now = null,
    ): PasswordSetupStatus {
        $now = $now ?? time();

        // Cheap shape check first, so a junk URL never reaches the database
        // work in phase 3 - but it is the only thing checked before the
        // comparison, and it leaks nothing about whether a row exists.
        if (preg_match('/^[0-9a-f]{64}$/', $presented) !== 1) {
            return PasswordSetupStatus::Malformed;
        }

        if ($storedHash === null || $storedHash === '') {
            return PasswordSetupStatus::Unknown;
        }

        if (!hash_equals($storedHash, self::hashOf($presented))) {
            return PasswordSetupStatus::Unknown;
        }

        // Spent before expired: a used link that has also aged out is still,
        // most interestingly, a used link.
        if ($usedAt !== null) {
            return PasswordSetupStatus::AlreadyUsed;
        }

        if ($now >= $expiresAt) {
            return PasswordSetupStatus::Expired;
        }

        return PasswordSetupStatus::Valid;
    }

    /**
     * The same check, throwing. The visible message is identical for every kind
     * of failure; the reason in getMessage() is for the audit log.
     *
     * @throws AuthError
     */
    public static function assertUsable(
        #[SensitiveParameter] string $presented,
        ?string $storedHash,
        int $expiresAt,
        ?int $usedAt = null,
        ?int $now = null,
    ): void {
        $status = self::check($presented, $storedHash, $expiresAt, $usedAt, $now);

        if (!$status->isValid()) {
            throw new AuthError('Set-password link refused: ' . $status->value, self::REFUSAL);
        }
    }
}
