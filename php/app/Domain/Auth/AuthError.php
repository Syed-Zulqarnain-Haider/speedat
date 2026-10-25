<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use RuntimeException;
use Throwable;

/**
 * Something went wrong while proving who somebody is.
 *
 * Every failure in this namespace carries TWO messages, on purpose:
 *
 *   getMessage()     the specific reason, for the log and the audit trail
 *                    ("token has expired", "unknown signing key"). It never
 *                    contains the token, the secret, the hash or the password.
 *   publicMessage()  what the visitor is allowed to read. By default the same
 *                    flat "Sign-in failed" the Next.js app returns, because a
 *                    sign-in screen that explains WHICH half of the credential
 *                    was wrong is a sign-in screen that answers questions for
 *                    whoever is guessing.
 *
 * The one deliberate exception is the unverified-email case, which the
 * TypeScript also spells out ("Verify your email address first") - it tells an
 * attacker nothing they did not already have to know to get that far.
 */
class AuthError extends RuntimeException
{
    /** The wording src/app/api/auth/session/route.ts returns for a bad token. */
    public const GENERIC = 'Sign-in failed';

    public function __construct(
        string $reason,
        private readonly string $visibleMessage = self::GENERIC,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    /** Safe to show a visitor. */
    public function publicMessage(): string
    {
        return $this->visibleMessage;
    }
}
