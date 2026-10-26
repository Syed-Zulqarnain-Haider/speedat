<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * A Firebase ID token was not acceptable. See AuthError for why there are two
 * messages; the reason is for the operator, publicMessage() for the visitor.
 *
 * NOT FINAL ANY MORE (2026-10-26), for exactly one subclass:
 * CertificatesUnavailable, which is "we could not check" rather than "we
 * checked and said no". Extending this class rather than AuthError keeps every
 * existing `catch (FirebaseTokenError)` failing closed - a caller that has not
 * learned the difference still refuses the sign-in.
 */
class FirebaseTokenError extends AuthError
{
}
