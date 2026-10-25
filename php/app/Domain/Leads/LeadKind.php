<?php

declare(strict_types=1);

namespace Speedat\Domain\Leads;

/**
 * Where a lead came from. The two entry points into the inbox:
 *
 *   quote    the customer tapped Book on a price. One lead per quote id,
 *            enforced by a unique index, so a double tap is one lead.
 *   message  the contact form. Every submission is its own lead, because two
 *            messages from the same person are two things to answer.
 */
final class LeadKind
{
    public const QUOTE   = 'quote';
    public const MESSAGE = 'message';

    /** @var list<string> */
    public const ALL = [self::QUOTE, self::MESSAGE];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
