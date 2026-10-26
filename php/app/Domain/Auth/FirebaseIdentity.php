<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * What a verified Firebase ID token says. Identity only - whether this person
 * may open the admin is the `admins` table's business, exactly as in
 * src/lib/auth/session.ts, where "a valid Google account that is not listed
 * gets nothing".
 *
 * Immutable: nothing downstream may edit a claim after it was checked.
 *
 * Per-property `readonly` rather than a readonly class, so this file still
 * parses on PHP 8.1 - the hosting plan is 8.2, but nothing here needs to be the
 * reason a downgrade fails at parse time rather than with a clear message.
 */
final class FirebaseIdentity
{
    /**
     * @param string               $uid           the token's `sub`, Firebase's account id
     * @param string|null          $email         null when the account has no email claim
     * @param bool                 $emailVerified the `email_verified` claim
     * @param int                  $issuedAt      `iat`, unix seconds
     * @param int                  $expiresAt     `exp`, unix seconds
     * @param int                  $authTime      `auth_time`: when the person actually
     *                                            authenticated. Required since 2026-10-26 -
     *                                            the sign-in's freshness check reads it
     * @param string               $signInProvider e.g. "google.com" or "password"; "unknown"
     *                                            when the token has no firebase claim
     * @param array<string,mixed>  $claims        every claim, for anything phase 3 needs
     */
    public function __construct(
        public readonly string $uid,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly int $authTime,
        public readonly string $signInProvider,
        public readonly array $claims,
    ) {
    }

    /**
     * The address to look up in `admins`, or null when the token has no email
     * or the address is unverified. The route handler in the TypeScript refuses
     * an unverified address with "Verify your email address first"; this is the
     * value it makes that decision on.
     */
    public function verifiedEmail(): ?string
    {
        if ($this->email === null || !$this->emailVerified) {
            return null;
        }

        // Addresses are stored lower-cased in `admins` (lookupAdmin() trims and
        // lower-cases before the query), so normalise here too.
        return strtolower(trim($this->email));
    }
}
