<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\SiteDoc\SiteData;

/**
 * The document the SEO tests assert against: the prototype's sample data,
 * the same one src/lib/site/seed.ts ships and the pricing tests pin to.
 *
 * Using the real seed rather than a two-field stub matters here. It is the
 * document with ten destinations (so `countryList()` takes its "and 7 more
 * countries" branch), with an address that ends in a pickup city (so the
 * PostalAddress split is exercised on a real envelope line), with an
 * opening-hours line written the way a human writes one, and with five FAQ
 * pairs - which is what the FAQ JSON-LD is checked against, one for one.
 */
final class Fixtures
{
    /** @return array<string,mixed> */
    public static function array(): array
    {
        return [
            'live'    => false,
            'company' => [
                'name'         => 'Speedat International Courier',
                'tagline'      => 'Speed Against Time',
                'origin'       => 'Pakistan',
                'whatsapp'     => '923157667076',
                'phone'        => '+92 315 766 7076',
                'email'        => 'info@speedat.net',
                'includes'     => 'Door-to-door delivery · Pickup on request · Tracking shared on WhatsApp',
                'originCities' => 'Lahore, Faisalabad',
                'notes'        => "Have the receiver's full address and phone number ready.",
            ],
            'settings' => [
                'pricingMode'       => 'slab',
                'currency'          => 'PKR',
                'volumetricDivisor' => 5000,
                'firstKg'           => 0.5,
                'stepKg'            => 0.5,
                // Kept on purpose, with the `doc` prices below: this is the
                // shape every version published before 2026-09-27 still has in
                // the store, and the SEO layer has to read one without
                // mentioning a document rate anywhere it writes.
                'docMaxKg'          => 0.5,
                'taxPct'            => 0,
                'roundTo'           => 10,
                'maxKg'             => 70,
                'showEta'           => true,
                'cutoffHour'        => 15,
                'workingDays'       => 'Mon, Tue, Wed, Thu, Fri, Sat',
                'holidays'          => '',
                'addons'            => 'Pickup and service charges | 500 | on',
                'disclaimer'        => 'Prices are confirmed when your shipment is weighed at pickup.',
            ],
            'services' => [
                ['id' => 'express', 'name' => 'Express', 'note' => 'Priority air service, door to door'],
                ['id' => 'normal', 'name' => 'Normal', 'note' => 'Economy air service, door to door'],
            ],
            'destinations' => [
                self::destination('gb', 'United Kingdom', 4500, 1100, '3–5', 3200, 850, '6–9', 3900, 2800),
                self::destination('us', 'United States', 5200, 1300, '4–6', 3800, 950, '7–10', 4500, 3300),
                self::destination('ca', 'Canada', 5400, 1350, '4–6', 3900, 980, '7–10', 4700, 3400),
                self::destination('au', 'Australia', 5600, 1400, '4–6', 4000, 1000, '7–10', 4900, 3500),
                self::destination('ae', 'United Arab Emirates', 3200, 800, '2–3', 2400, 600, '4–6', 2800, 2100),
                self::destination('sa', 'Saudi Arabia', 3400, 850, '2–4', 2500, 650, '4–7', 3000, 2200),
                self::destination('de', 'Germany', 4700, 1150, '3–5', 3300, 880, '6–9', 4100, 2900),
                self::destination('it', 'Italy', 4700, 1150, '3–5', 3300, 880, '6–9', 4100, 2900),
                self::destination('tr', 'Türkiye', 4300, 1050, '3–5', 3100, 800, '6–9', 3700, 2700),
                self::destination('my', 'Malaysia', 4100, 1000, '3–5', 3000, 780, '6–9', 3600, 2600),
            ],
            'content' => [
                'heroTitle'    => '',
                'heroSub'      => '',
                'stats'        => '',
                'promise'      => 'Confirmed at pickup when we weigh and measure the parcel.',
                'routesTitle'  => 'Rates',
                'routesNote'   => '',
                'stepsTitle'   => '',
                'steps'        => '',
                'servicesTitle' => '',
                // /services takes its meta description straight from this line
                // (Meta::description). It promised a flat document rate until
                // the owner removed the document rate from the product on
                // 2026-09-27; the sentence now says what is true of every
                // shipment, and matches Seed::content() word for word.
                'servicesLede' => 'Two air services from Lahore, both door to door: Express and Normal. Everything is priced by weight. Heavier cargo is quoted on WhatsApp.',
                'ctaTitle'     => '',
                'ctaSub'       => '',
                'contactLede'  => 'One WhatsApp number. We answer during working hours and confirm every pickup in the chat.',
                'faqLede'      => '',
                'services'     => implode("\n", [
                    'plane | Express | Priority air. The faster of the two on every route; the days are in the table.',
                    'globe | Normal | Economy air. Same handling, a few days longer, the lower price.',
                    // "Documents" is still one of the things the company sends,
                    // and JSON-LD still offers it by that name. What it no
                    // longer is, is a PRICE: no flat rate per envelope.
                    'doc | Documents | Passports, certificates, contracts. Priced by weight like any parcel.',
                    'box | Cargo | Above the calculator\'s top weight, commercial goods or many boxes: send the details on WhatsApp.',
                    'truck | Pickup | We collect from your door in our pickup cities.',
                    'shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.',
                ]),
                'story' => implode("\n", [
                    'Speedat International Courier is a courier office in Model Town, Lahore. We send documents, parcels and cargo abroad through partner airlines and express networks, collecting from your door and delivering to the receiver\'s.',
                    'The price on this site is the price we charge.',
                ]),
                'mission' => '',
                'vision'  => '',
                'values'  => '',
                'address' => 'Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model Town, Lahore',
                'hours'   => 'Monday to Saturday, 9 am – 7 pm',
                'mapUrl'  => '',
                'phone2'  => '+92 334 77 33 328',
                'faq'     => implode("\n", [
                    'How is the price worked out? | By weight: the higher of the scale weight and the volumetric weight (length × width × height), rounded up to the next half kilo.',
                    'What cannot be sent? | What airlines refuse: loose lithium batteries, aerosols, flammable liquids, perfume above the limit, cash, fresh food.',
                    'Who pays duties at the destination? | The receiver, when the country charges them.',
                    'How do I book? | Tap Book on WhatsApp under the price and send the message.',
                    'How do I track it? | We send the tracking number on WhatsApp as soon as the airline has the shipment.',
                ]),
            ],
            'importProfiles' => [],
        ];
    }

    public static function document(): SiteData
    {
        return SiteData::fromArray(self::array());
    }

    /** The published document as the site reads it: version 7, published on a fixed date. */
    public static function facts(): SiteFacts
    {
        return new SiteFacts(self::document(), 7, '2026-09-20T06:15:00Z');
    }

    /** The same document before anything has been published - version 0, no timestamp. */
    public static function unpublished(): SiteFacts
    {
        return new SiteFacts(self::document());
    }

    /**
     * A document whose every text field is a cross-site-scripting attempt, for
     * the escaping tests. Same shape, hostile contents.
     */
    public static function hostile(): SiteFacts
    {
        $doc = self::array();

        $doc['company']['name']    = 'Acme "</script><script>alert(1)</script> & Co';
        $doc['company']['tagline'] = "Speed <img src=x onerror=alert('x')>";
        $doc['content']['faq']     = 'Is it safe? | Yes </script><script>alert(2)</script> & always';
        $doc['content']['address'] = '<b>Office</b> 1, Model Town, Lahore';
        $doc['content']['contactLede'] = 'Call us & "book" <now>';

        return new SiteFacts(SiteData::fromArray($doc), 3, '2026-09-20T06:15:00Z');
    }

    /** @return array<string,mixed> */
    private static function destination(
        string $id,
        string $name,
        int $expressFirst,
        int $expressAddl,
        string $expressDays,
        int $normalFirst,
        int $normalAddl,
        string $normalDays,
        int $expressDoc,
        int $normalDoc,
    ): array {
        return [
            'id'     => $id,
            'name'   => $name,
            'active' => true,
            'rates'  => [
                'express' => ['first' => $expressFirst, 'addl' => $expressAddl, 'days' => $expressDays, 'doc' => $expressDoc],
                'normal'  => ['first' => $normalFirst, 'addl' => $normalAddl, 'days' => $normalDays, 'doc' => $normalDoc],
            ],
        ];
    }
}
