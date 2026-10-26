<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Settings;

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
     * The same sentence with the word "Free" taken out, for a site that
     * charges for pickup.
     *
     * 2026-10-26 - owner: the pickup charge is shown under the quote, so the
     * site must not also say pickup is free. A line "Pickup | 1000 | separate"
     * puts "Pickup charge: PKR 1,000 - paid separately" in the drill-down
     * under every price, and the paragraph above the calculator promising
     * free pickup would then contradict the one number the owner asked us to
     * show. Only the claim goes: the areas, the phases and the coverage are
     * still true, and they are what the local search matches on.
     */
    public const PICKUP_SENTENCE_CHARGED =
        'Pickup from anywhere in Lahore - including all DHA phases (Phase 1 to '
        . 'Phase 12), Bahria Town, Model Town, Johar Town, Gulberg, Garden Town, '
        . 'Faisal Town, Allama Iqbal Town, Wapda Town, Valencia and Cantt.';

    /**
     * Does this rate document charge for pickup? The ONE answer every "free
     * pickup" sentence on the site asks (the home section below, the meta and
     * JSON-LD description in SiteFacts, the terms page), so they flip
     * together and can never disagree with each other or with the quote.
     *
     * 2026-10-26 - owner: the pickup charge is shown under the quote, so the
     * site must not also say pickup is free. A pickup charge reaches the
     * customer two ways, and either one makes "free" untrue:
     *
     *  - a separate charge (Engine::separateCharges(), "| separate" or
     *    "| info"): never added, but printed under the quote by name;
     *  - an add-on that is ON (Engine::addonsList(), on = true): added into
     *    every tile, board figure and quote, and named in the board caption
     *    ("pickup and service charges PKR 500 included").
     *
     * An add-on that is OFF is a tick box the customer chooses, not a charge
     * for the pickup itself, so it does not count; nor does a 0 amount (the
     * Engine already drops a separate 0, and an "on" 0 adds nothing).
     *
     * Labels are read through the Engine's own parsers, so the label here is
     * exactly the trimmed text the drill-down and the caption print - no
     * second parse that could disagree about which line is which. The match
     * is on the label because the label is the only thing that says what a
     * charge is for: "Pickup", "Pickup and service charges", "Pick-up",
     * "Collection". "Delivery" (the live site's charge) is not a pickup
     * charge, so the live wording does not change.
     */
    public static function pickupCharged(Settings $settings): bool
    {
        foreach (Engine::separateCharges($settings) as $charge) {
            if (self::isPickupLabel($charge->label)) {
                return true;
            }
        }

        foreach (Engine::addonsList($settings) as $addon) {
            if ($addon->on && $addon->amount > 0 && self::isPickupLabel($addon->label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The visible pickup sentence for this document: today's exact wording
     * when pickup is free, the same sentence without "Free" when it is
     * charged (see pickupCharged()).
     */
    public static function pickupSentence(Settings $settings): string
    {
        return self::pickupCharged($settings) ? self::PICKUP_SENTENCE_CHARGED : self::PICKUP_SENTENCE;
    }

    /**
     * "Pickup", "pick-up", "pick up" or "collection", any case. The Engine
     * has already trimmed the label (Engine::parts()); trim() again costs
     * nothing and keeps this safe for a caller that hands in raw text.
     */
    private static function isPickupLabel(string $label): bool
    {
        return preg_match('/pick[\s-]?up|collection/i', trim($label)) === 1;
    }

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
