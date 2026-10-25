<?php

declare(strict_types=1);

namespace Speedat\Domain\Leads;

/**
 * Where a lead has got to. Port of `LeadStatus` in src/lib/leads.ts.
 *
 * Four values, in the order the admin's filter tabs show them, which is also
 * the order a lead usually travels in. A constant class rather than an enum so
 * it matches the rest of this codebase (Settings::MODE_SLAB,
 * PriceResult::REASON_WEIGHT) and so a value read straight out of a VARCHAR
 * column needs no unwrapping before it can be compared.
 */
final class LeadStatus
{
    public const NEW       = 'new';
    public const CONTACTED = 'contacted';
    public const BOOKED    = 'booked';
    public const LOST      = 'lost';

    /** Every status, in display order. @var list<string> */
    public const ALL = [self::NEW, self::CONTACTED, self::BOOKED, self::LOST];

    /** The filter value meaning "do not filter". */
    public const ANY = 'all';

    /** What the admin reads. */
    public const LABEL = [
        self::NEW       => 'New',
        self::CONTACTED => 'Contacted',
        self::BOOKED    => 'Booked',
        self::LOST      => 'Lost',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** A stored value narrowed to one we know; anything else reads as new. */
    public static function orNew(string $status): string
    {
        return self::isValid($status) ? $status : self::NEW;
    }

    public static function label(string $status): string
    {
        return self::LABEL[$status] ?? ucfirst($status);
    }
}
