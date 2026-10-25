<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * The Lahore service area, in ONE place.
 *
 * The owner confirmed (2026-10-01) that Speedat picks up from ANYWHERE in
 * Lahore, and named the neighbourhoods that matter most for his customers:
 * Model Town, Johar Town, Bahria Town, DHA (all phases), and on across the
 * city. Those searches - "courier in DHA Lahore", "international courier Johar
 * Town", "send parcel abroad from Model Town" - are the local audience, so the
 * area names belong in the page the way a customer would type them.
 *
 * This is the single source of truth. The visible "Pickup across Lahore"
 * section and the LocalBusiness `areaServed` structured data both read it, so
 * the two can never say different things - the rule the whole SEO layer is
 * built on (see SiteFacts): nothing in the markup claims what the page does
 * not show, and nothing is invented. Every name here is a real Lahore area the
 * owner serves.
 *
 * It is a constant rather than an owner-editable field on purpose, for now:
 * the coverage is "all of Lahore" and does not change week to week, and a
 * hand-typed list in the admin is one more thing to get wrong. If coverage
 * ever narrows, this list narrows with it - it must stay TRUE, not aspirational.
 */
final class LocalAreas
{
    /** The city, for `areaServed` and the opening sentence. */
    public const CITY = 'Lahore';

    /**
     * The neighbourhoods named as distinct service areas. Kept to the real,
     * well-known ones a customer searches by - not padded, because a
     * structured-data list stuffed with near-duplicates reads as manipulation
     * to the very search engine it is meant to help.
     *
     * @var list<string>
     */
    public const NEIGHBOURHOODS = [
        'DHA Lahore',
        'Bahria Town Lahore',
        'Model Town Lahore',
        'Johar Town Lahore',
        'Gulberg Lahore',
        'Garden Town Lahore',
        'Faisal Town Lahore',
        'Allama Iqbal Town Lahore',
        'Wapda Town Lahore',
        'Valencia Town Lahore',
        'Cantt Lahore',
    ];

    /**
     * How the areas read in a visible sentence - the DHA phases spelled out so
     * that a "DHA Phase 5 Lahore" search matches the body text, which is where
     * a local query is actually matched (structured data explains, body text
     * ranks). Natural prose, not a keyword list.
     */
    public const PICKUP_SENTENCE =
        'Free pickup from anywhere in Lahore - including all DHA phases (Phase 1 to '
        . 'Phase 12), Bahria Town, Model Town, Johar Town, Gulberg, Garden Town, '
        . 'Faisal Town, Allama Iqbal Town, Wapda Town, Valencia and Cantt.';

    /**
     * The chips shown in the "Pickup across Lahore" section - short labels,
     * without the "Lahore" suffix the structured data carries, because the
     * section heading already says Lahore.
     *
     * @return list<string>
     */
    public static function chips(): array
    {
        return [
            'DHA (all phases)',
            'Bahria Town',
            'Model Town',
            'Johar Town',
            'Gulberg',
            'Garden Town',
            'Faisal Town',
            'Allama Iqbal Town',
            'Wapda Town',
            'Valencia',
            'Cantt',
            'and all of Lahore',
        ];
    }
}
