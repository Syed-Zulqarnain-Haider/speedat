<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\Engine;

/**
 * The sample document, word for word from src/lib/site/seed.ts (brief v3).
 *
 * It does three jobs and all three depend on it being exact:
 *   - it seeds a fresh database,
 *   - it is the fallback the public site falls back to when the database
 *     cannot be reached,
 *   - it is the default every missing field of an old document is filled from,
 *     so the text here decides what a version published two years ago renders.
 *
 * `live: false` keeps the "sample rates" notice on the site until the owner
 * publishes real rates and ticks the box.
 *
 * Every non-ASCII character is written as a \u{...} escape on purpose: an
 * editor that silently re-encodes a file must not be able to turn a middle dot
 * into a question mark in the copy customers read.
 */
final class Seed
{
    /**
     * The document as stored JSON: the shape `SiteData::fromArray()` reads and
     * `Migrate` merges missing fields from.
     *
     * @return array<string,mixed>
     */
    public static function data(): array
    {
        return [
            'live'         => false,
            'company'      => self::company(),
            'settings'     => self::settings(),
            'services'     => [
                ['id' => 'express', 'name' => 'Express', 'note' => 'Priority air service, door to door'],
                ['id' => 'normal', 'name' => 'Normal', 'note' => 'Economy air service, door to door'],
            ],
            'destinations' => self::destinations(),
            'content'      => self::content(),
            'promo'        => self::promo(),
            'reviews'      => self::reviews(),
            'importProfiles' => [],
        ];
    }

    /**
     * A few sample reviews, SO THE OWNER SEES WHAT THE BLOCK DOES AND REPLACES
     * THEM. They are placeholders, not real customers: generic, un-attributed
     * to any named person, and clearly the kind of thing the owner swaps for
     * the reviews he picks from his Google Business profile. The file never
     * invents a real name, a real count or a star total it has not been given.
     *
     * @return list<array<string,mixed>>
     */
    public static function reviews(): array
    {
        return [
            [
                'author' => 'Sample review',
                'rating' => 5,
                'text'   => 'Fast and smooth - parcel reached London in 4 days and the pickup was on time.',
                'date'   => 'March 2026',
                'show'   => true,
            ],
            [
                'author' => 'Sample review',
                'rating' => 5,
                'text'   => 'Clear price before booking and no surprises at pickup. Tracking came through on WhatsApp.',
                'date'   => 'February 2026',
                'show'   => true,
            ],
            [
                'author' => 'Sample review',
                'rating' => 5,
                'text'   => 'Sent documents to Dubai and they arrived quickly. Helpful staff on the phone.',
                'date'   => 'January 2026',
                'show'   => true,
            ],
        ];
    }

    /**
     * The promo popup, disabled and empty. It ships OFF: a fresh site shows no
     * popup until an admin turns it on and types something, and this file must
     * never invent a discount, a percentage or an offer of its own.
     *
     * @return array<string,mixed>
     */
    public static function promo(): array
    {
        return [
            'enabled'  => false,
            'title'    => '',
            'message'  => '',
            'ctaLabel' => '',
            'ctaHref'  => '',
        ];
    }

    /** @return array<string,mixed> */
    public static function company(): array
    {
        return [
            'name'         => 'Speedat International Courier',
            'tagline'      => 'Speed Against Time',
            'origin'       => 'Pakistan',
            'whatsapp'     => '923157667076',
            'phone'        => '+92 315 766 7076',
            'email'        => 'info@speedat.net',
            'includes'     => "Door-to-door delivery \u{00b7} Pickup on request \u{00b7} Tracking shared on WhatsApp",
            'originCities' => 'Lahore',
            'notes'        => implode("\n", [
                "Have the receiver's full address and phone number ready.",
                "Some items cannot be sent by air (batteries, liquids, perishables) - ask us if unsure.",
                'Any duties or taxes charged at the destination are paid by the receiver.',
            ]),
        ];
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        return [
            'pricingMode'       => 'slab',
            'currency'          => 'PKR',
            'volumetricDivisor' => 5000,
            'firstKg'           => 0.5,
            'stepKg'            => 0.5,
            'taxPct'            => 0,
            'roundTo'           => 10,
            'maxKg'             => 70,
            'showEta'           => true,
            'cutoffHour'        => 15,
            'workingDays'       => 'Mon, Tue, Wed, Thu, Fri, Sat',
            'holidays'          => '',
            'addons'            => 'Delivery | 1000 | on',
            'disclaimer'        => 'Prices are for the weight and size entered here and are confirmed when your shipment is '
                . 'weighed and measured at pickup. Delivery dates are estimates and exclude customs delays.',
        ];
    }

    /**
     * Brief v3 section 1: every customer sentence carries a place, a number or
     * a day count, or it is generated from the rate document. Blank = automatic
     * for heroTitle, heroSub and routesNote. No number that lives in settings or
     * company is typed here; the page computes it.
     *
     * @return array<string,string>
     */
    public static function content(): array
    {
        return [
            'heroTitle'   => '',
            'heroSub'     => '',
            'stats'       => '',
            'promise'     => 'Confirmed at pickup when we weigh and measure the parcel. '
                . 'Duties at the destination, if any, are paid by the receiver.',
            'routesTitle' => 'Rates',
            'routesNote'  => '',
            // Hidden in the admin form and rendered nowhere; the keys stay so old documents load.
            'stepsTitle'    => '',
            'steps'         => '',
            'servicesTitle' => '',
            // Every shipment is priced by weight since 2026-09-27: no sentence
            // here may promise a flat rate for anything.
            'servicesLede'  => 'Two air services from Lahore, both door to door: Express and Normal. '
                . 'Everything is priced by weight. Heavier cargo is quoted on WhatsApp.',
            'ctaTitle'    => '',
            'ctaSub'      => '',
            'contactLede' => 'One WhatsApp number. We answer during working hours and confirm every pickup in the chat.',
            'faqLede'     => '',
            // `icon | Title | text`: the icon token is parsed and ignored (v3 prints no
            // icons); /services adds the cities, the cutoff hour and maxKg itself.
            'services' => implode("\n", [
                'plane | Express | Priority air. The faster of the two on every route; the days are in the table.',
                'globe | Normal | Economy air. Same handling, a few days longer, the lower price.',
                'doc | Documents | Passports, certificates, contracts. Priced by weight like any parcel, '
                    . 'and light enough that it is usually the cheapest row in the table.',
                "box | Cargo | Above the calculator's top weight, commercial goods or many boxes: "
                    . 'send the details on WhatsApp and we quote within the hour.',
                'truck | Pickup | We collect from your door in our pickup cities; book before the cutoff and we come the same day.',
                'shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.',
            ]),
            'story' => implode("\n", [
                'Speedat International Courier is a courier office in Model Town, Lahore. We send documents, '
                    . 'parcels and cargo abroad through partner airlines and express networks, collecting from '
                    . "your door and delivering to the receiver's.",
                'The price on this site is the price we charge: you see it before you book, we confirm it at '
                    . 'pickup when the parcel is weighed, and we send the tracking on WhatsApp once the shipment '
                    . 'is with the airline.',
            ]),
            'mission' => '',
            'vision'  => '',
            'values'  => '',
            'address' => 'Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model Town, Lahore',
            'hours'   => "Monday to Saturday, 9 am \u{2013} 7 pm",
            'mapUrl'  => '',
            'phone2'  => '+92 334 77 33 328',
            'faq'     => implode("\n", [
                'How is the price worked out? | By weight: the higher of the scale weight and the volumetric '
                    . "weight (length \u{00d7} width \u{00d7} height), rounded up to the next half kilo. "
                    . 'The detailed price shows the working.',
                'What cannot be sent? | What airlines refuse: loose lithium batteries, aerosols, flammable '
                    . 'liquids, perfume above the limit, cash, fresh food. Ask on WhatsApp if unsure.',
                'Who pays duties at the destination? | The receiver, when the country charges them.',
                'How do I book? | Tap Book on WhatsApp under the price and send the message. '
                    . 'We confirm the pickup time in the chat.',
                'How do I track it? | We send the tracking number on WhatsApp as soon as the airline has the shipment.',
            ]),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function destinations(): array
    {
        $dash = "\u{2013}";

        return [
            self::destination('gb', 'United Kingdom', 4500, 1100, '3' . $dash . '5', 3200, 850, '6' . $dash . '9'),
            self::destination('us', 'United States', 5200, 1300, '4' . $dash . '6', 3800, 950, '7' . $dash . '10'),
            self::destination('ca', 'Canada', 5400, 1350, '4' . $dash . '6', 3900, 980, '7' . $dash . '10'),
            self::destination('au', 'Australia', 5600, 1400, '4' . $dash . '6', 4000, 1000, '7' . $dash . '10'),
            self::destination('ae', 'United Arab Emirates', 3200, 800, '2' . $dash . '3', 2400, 600, '4' . $dash . '6'),
            self::destination('sa', 'Saudi Arabia', 3400, 850, '2' . $dash . '4', 2500, 650, '4' . $dash . '7'),
            self::destination('de', 'Germany', 4700, 1150, '3' . $dash . '5', 3300, 880, '6' . $dash . '9'),
            self::destination('it', 'Italy', 4700, 1150, '3' . $dash . '5', 3300, 880, '6' . $dash . '9'),
            self::destination('tr', "T\u{00fc}rkiye", 4300, 1050, '3' . $dash . '5', 3100, 800, '6' . $dash . '9'),
            self::destination('my', 'Malaysia', 4100, 1000, '3' . $dash . '5', 3000, 780, '6' . $dash . '9'),
        ];
    }

    /** The whole document as value objects. */
    public static function site(): SiteData
    {
        return SiteData::fromArray(self::data());
    }

    /**
     * The sample data as it ships to a new site: per-kilogram price boxes up to
     * the 25 kg cargo threshold, each box filled from the slab prices above with
     * `slabToGrid`, so the numbers stay the ones the prototype was checked
     * against. Port of `gridSeed()`.
     */
    public static function gridSite(): SiteData
    {
        $doc                        = self::data();
        $doc['settings']['pricingMode'] = 'grid';
        $doc['settings']['maxKg']       = 25;

        $site     = SiteData::fromArray($doc);
        $settings = $site->settings();

        $destinations = [];
        foreach ($site->card->destinations as $destination) {
            $rates = [];
            foreach ($destination->rates as $serviceId => $rate) {
                $rates[$serviceId] = $rate->withGrid(Engine::slabToGrid($settings, $rate));
            }

            $destinations[] = new \Speedat\Domain\Pricing\Destination(
                $destination->id,
                $destination->name,
                $destination->active,
                $rates,
            );
        }

        return $site->withCard(new \Speedat\Domain\Pricing\RateCard($site->card->services, $destinations, $settings));
    }

    /** @return array<string,mixed> */
    public static function gridData(): array
    {
        return self::gridSite()->toArray();
    }

    /** @return array<string,mixed> */
    private static function destination(
        string $id,
        string $name,
        float $expressFirst,
        float $expressAddl,
        string $expressDays,
        float $normalFirst,
        float $normalAddl,
        string $normalDays,
    ): array {
        return [
            'id'     => $id,
            'name'   => $name,
            'active' => true,
            'rates'  => [
                'express' => ['first' => $expressFirst, 'addl' => $expressAddl, 'days' => $expressDays],
                'normal'  => ['first' => $normalFirst, 'addl' => $normalAddl, 'days' => $normalDays],
            ],
        ];
    }
}
