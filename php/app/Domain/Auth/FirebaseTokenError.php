<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * A Firebase ID token was not acceptable. See AuthError for why there are two
 * messages; the reason is for the operator, publicMessage() for the visitor.
 */
final class FirebaseTokenError extends AuthError
{
}
