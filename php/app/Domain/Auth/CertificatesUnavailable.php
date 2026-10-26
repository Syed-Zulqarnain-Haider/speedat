<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * Google's signing certificates could not be obtained at all, so NO token can
 * be checked - the forged one and the owner's real one alike.
 *
 * WHY THIS IS ITS OWN CLASS (2026-10-26). A host that blocks outbound HTTPS, a
 * Google outage past the 24-hour grace window and a cache directory inside the
 * web root all used to surface as the same 401 "That Google sign-in was
 * refused" a forged token gets. The owner then tried again, and again, and
 * concluded his account had been removed; nothing on the page pointed at the
 * server. LoginController catches this BEFORE the generic FirebaseTokenError,
 * answers 503 with a sentence about the server, and logs
 * `login_google_unavailable` with the reason.
 *
 * It reveals nothing about who is an admin: it is thrown before any claim in
 * the token - the email included - has been read, so a stranger's token and
 * the owner's get the same 503.
 *
 * It extends FirebaseTokenError so that any caller that does NOT know about it
 * still refuses. Failing closed is not negotiable; only the wording changes.
 */
final class CertificatesUnavailable extends FirebaseTokenError
{
    public function __construct(string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 'Sign-in is temporarily unavailable', $previous);
    }
}
