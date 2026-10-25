<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * Why a "set your password" link was or was not accepted.
 *
 * The caller gets the specific reason so it can be audited; the person at the
 * browser gets one wording for every failure (see PasswordSetupToken::REFUSAL),
 * because "this link expired" and "this link was already used" together tell a
 * stranger holding a stolen link rather more than they should learn.
 */
enum PasswordSetupStatus: string
{
    case Valid       = 'valid';
    case Malformed   = 'malformed';
    case Unknown     = 'unknown';
    case Expired     = 'expired';
    case AlreadyUsed = 'already_used';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}
