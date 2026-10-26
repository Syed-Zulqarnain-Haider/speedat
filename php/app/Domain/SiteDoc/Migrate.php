<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\Num;

/**
 * Bring a stored document up to the current shape. Port of
 * src/lib/site/migrate.ts.
 *
 * Old snapshots (and the prototype's exported data) may lack fields added
 * later; every missing field gets the same default the prototype used, so
 * restoring version 1 years from now still renders. This is what makes the
 * version history a promise rather than a filing cabinet.
 *
 * Website text that still reads exactly as an earlier round's default was never
 * the owner's own words, so it follows the current default: a v2 headline kept
 * verbatim would print its accent asterisks on a v3 page. Anything the owner
 * typed himself is left exactly as typed.
 */
final class Migrate
{
    /**
     * Every default a content field had in an earlier round (v1 "Manifest",
     * v2 "Three taps"), keyed by field. Copied character for character from
     * migrate.ts: a single changed letter here would leave an old default on a
     * v3 page forever, because it would no longer be recognised.
     *
     * @return array<string,list<string>>
     */
    public static function retiredDefaults(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $rsquo = "\u{2019}";  // right single quotation mark
        $mdash = "\u{2014}";  // em dash
        $ndash = "\u{2013}";  // en dash
        $ldquo = "\u{201c}";
        $rdquo = "\u{201d}";
        $times = "\u{00d7}";
        $div   = "\u{00f7}";

        $map = [
            'heroTitle' => [
                'Send anything *abroad* from Lahore. Priced in seconds.',
                'Send anything *abroad*. See your price now.',
            ],
            'heroSub' => [
                'Documents, parcels and cargo, collected from your door and delivered to the receiver' . $rsquo
                    . 's. Get an instant price, then book on WhatsApp.',
                'Tap your country and the weight. Your price appears ' . $mdash . ' then book on WhatsApp.',
            ],
            'promise' => [
                'The price you see here is the price at pickup, unless the parcel weighs or measures differently.',
                'The price you see is the price at pickup, unless the parcel weighs or measures differently.',
            ],
            'routesTitle' => ['Where we fly from Lahore', 'Where we deliver'],
            'routesNote'  => [
                'Fastest service in working days. 1 kg parcel, shipping only; pickup charges are added in the quote.',
                'Price for a 1 kg parcel with the cheapest service. Pickup is added in your price.',
            ],
            'stepsTitle' => ['Three steps, no surprises', 'Three taps, one price'],
            'steps'      => [
                implode("\n", [
                    'Price it | Choose the destination and weight. The price on this page is the price at pickup '
                        . 'unless the parcel weighs or measures differently.',
                    'Book on WhatsApp | Tap Book, send the ready-made message, and we confirm the pickup time and '
                        . 'receiver details in the chat.',
                    'Track to the door | We share the tracking number and updates on WhatsApp until it is delivered.',
                ]),
                implode("\n", [
                    'Tap your country | Pick where the parcel is going.',
                    'Tap the weight | 1 to 25 kg. Not sure? Pick the nearest ' . $mdash . ' we weigh it at pickup.',
                    'See the price and book | Tap the green button. We reply on WhatsApp and collect from your door.',
                ]),
            ],
            'servicesTitle' => ['What we carry', 'Two ways to send'],
            'ctaTitle' => ['Ready to send something?', 'Ready to send?'],
            'ctaSub'   => [
                'Get an instant price and book on WhatsApp in two minutes.',
                'Tap WhatsApp or call. A person answers.',
            ],
            'contactLede' => [
                'WhatsApp is the fastest way to reach us. We reply during working hours and confirm every pickup in the chat.',
            ],
            'faqLede' => ['If yours is not here, ask us on WhatsApp.'],
            /*
             * The v3 default, retired on 2026-09-27 when the document rate left
             * the product. It promised "Documents have a flat rate", so a
             * document that never had this sentence edited must not go on saying
             * it - and because this list is matched character for character,
             * an owner who typed his own lede keeps it.
             */
            // ONE key, both retired texts. It used to be written twice in this
            // array, and PHP keeps only the last duplicate key: the v1/v2 lede
            // ("Every service is door to door ...") was silently never retired.
            'servicesLede' => [
                'Every service is door to door: we collect from you and deliver to the receiver. '
                    . 'Prices are on the quote page.',
                'Two air services from Lahore, both door to door: Express and Normal. '
                    . 'Documents have a flat rate. Heavier cargo is quoted on WhatsApp.',
            ],
            'services' => [
                implode("\n", [
                    'plane | Express international | Priority air service to most destinations in 3' . $ndash
                        . '6 working days, picked up from your door and delivered to the receiver' . $rsquo . 's.',
                    'globe | Economy international | A lower-cost air service for parcels that are not urgent. '
                        . 'Same door-to-door handling, a few days longer.',
                    'doc | Documents | Passports, certificates, contracts and letters at a flat document rate, '
                        . 'with tracking shared on WhatsApp.',
                    'box | Cargo and bulk shipments | Over 70 kg, commercial goods or many boxes? Send us the '
                        . 'details and we quote a cargo rate within the hour.',
                    'truck | Pickup on request | We collect from homes and offices in Lahore. '
                        . 'Tell us the address when you book.',
                    'shield | Packing and paperwork | Advice on safe packing, what can fly, and the customs '
                        . 'paperwork your destination needs.',
                ]),
                implode("\n", [
                    'plane | Express international | Priority air service to most destinations in 3' . $ndash
                        . '6 working days, picked up from your door and delivered to the receiver' . $rsquo . 's.',
                    'globe | Economy international | A lower-cost air service for parcels that are not urgent. '
                        . 'Same door-to-door handling, a few days longer.',
                    'doc | Documents | Passports, certificates, contracts and letters at a flat document rate, '
                        . 'with tracking shared on WhatsApp.',
                    'box | Cargo and bulk shipments | A very heavy parcel, commercial goods or many boxes? '
                        . 'Send us the details and we quote a cargo rate within the hour.',
                    'truck | Pickup on request | We collect from homes and offices in Lahore. '
                        . 'Tell us the address when you book.',
                    'shield | Packing and paperwork | Advice on safe packing, what can fly, and the customs '
                        . 'paperwork your destination needs.',
                ]),
                // The v3 default, retired 2026-09-27 with the document rate: its
                // Documents line advertised "A flat rate per envelope".
                implode("\n", [
                    'plane | Express | Priority air. The faster of the two on every route; the days are in the table.',
                    'globe | Normal | Economy air. Same handling, a few days longer, the lower price.',
                    'doc | Documents | Passports, certificates, contracts. A flat rate per envelope, in the table.',
                    "box | Cargo | Above the calculator's top weight, commercial goods or many boxes: "
                        . 'send the details on WhatsApp and we quote within the hour.',
                    'truck | Pickup | We collect from your door in our pickup cities; book before the cutoff and we come the same day.',
                    'shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.',
                ]),
                // The v4 default, retired 2026-10-25: its Documents line sent the
                // customer to "the cheapest row in the table", and the table has
                // had no documents row since 2026-09-27.
                implode("\n", [
                    'plane | Express | Priority air. The faster of the two on every route; the days are in the table.',
                    'globe | Normal | Economy air. Same handling, a few days longer, the lower price.',
                    'doc | Documents | Passports, certificates, contracts. Priced by weight like any parcel, '
                        . 'and light enough that it is usually the cheapest row in the table.',
                    "box | Cargo | Above the calculator's top weight, commercial goods or many boxes: "
                        . 'send the details on WhatsApp and we quote within the hour.',
                    'truck | Pickup | We collect from your door in our pickup cities; book before the cutoff and we come the same day.',
                    'shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.',
                ]),
            ],
            'story' => [
                implode("\n", [
                    'Speedat International Courier is based in Lahore, Pakistan. We move documents, parcels and '
                        . 'cargo to destinations worldwide through partner airlines and express networks, '
                        . 'collecting from your door and delivering to the receiver' . $rsquo . 's.',
                    'Our slogan is ' . $ldquo . 'Speed Against Time' . $rdquo . ', and we mean it: clear prices '
                        . 'before you book, pickup when you need it, and updates on WhatsApp until your shipment '
                        . 'is delivered.',
                ]),
            ],
            'mission' => ['To move every shipment with speed, care and a price you knew before you booked.'],
            'vision'  => [
                'To be the courier that families and businesses across Pakistan trust first when something has '
                    . 'to reach abroad.',
            ],
            'values' => [
                implode("\n", [
                    'Speed | Same-day pickup before the cutoff and the fastest service that fits your budget.',
                    'Honesty | The price on this page is the price at pickup, unless the parcel weighs or '
                        . 'measures differently.',
                    'Care | Your shipment is handled as if it were our own, from packing advice to the last mile.',
                    'Reachability | A real person answers on WhatsApp, and you get updates at every step.',
                ]),
            ],
            'faq' => [
                implode("\n", [
                    'How is the price calculated? | By the higher of actual weight and volumetric weight (length '
                        . $times . ' width ' . $times . ' height in cm ' . $div . ' 5000), rounded up to the next '
                        . '0.5 kg. Every quote shows the working.',
                    'What can I not send? | Anything airlines refuse: lithium batteries on their own, aerosols, '
                        . 'flammable liquids, perfumes over the allowed limit, cash, and perishable food. Ask us '
                        . 'on WhatsApp if you are unsure.',
                    'Who pays duties and taxes at the destination? | The receiver, if the destination charges '
                        . 'them. We tell you when a country usually does.',
                    'How do I book? | Get a quote here, tap ' . $ldquo . 'Book on WhatsApp' . $rdquo . ', and send '
                        . 'the message. We confirm the pickup time and the receiver details in the chat.',
                    'How do I track my shipment? | We share the tracking number and updates with you on WhatsApp '
                        . 'as soon as the shipment is handed to the airline.',
                ]),
            ],
        ];

        return $map;
    }

    /**
     * A stored text that is exactly an earlier round's default becomes the
     * current default; typed text is kept.
     *
     * @param  array<string,mixed> $content
     * @return array<string,string>
     */
    public static function upgradeContent(array $content): array
    {
        $seed = Seed::content();
        $out  = $content;

        foreach (self::retiredDefaults() as $key => $retired) {
            $stored = Text::trim(Text::js($out[$key] ?? ''));

            foreach ($retired as $old) {
                if (Text::trim($old) === $stored) {
                    $out[$key] = $seed[$key] ?? '';
                    break;
                }
            }
        }

        /** @var array<string,string> $strings */
        $strings = [];
        foreach ($out as $key => $value) {
            $strings[(string) $key] = Text::js($value);
        }

        return $strings;
    }

    /**
     * Upgrade any stored document to the current shape.
     *
     * @param array<string,mixed> $input the raw JSON of a stored version or draft
     */
    public static function migrate(array $input): SiteData
    {
        $rawSettings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $settings    = array_merge(Seed::settings(), $rawSettings);

        // The prototype stored a blank cutoff as ""; we store null. Read from the
        // RAW settings, not the merged ones: a document that never carried a
        // cutoff has none, and inheriting the seed's 3 pm here would invent a
        // same-day deadline the owner never set.
        $rawCutoff = $rawSettings['cutoffHour'] ?? null;
        $cutoff    = ($rawCutoff === '' || $rawCutoff === null) ? null : Num::jsNumber($rawCutoff);
        if ($cutoff !== null && !is_finite($cutoff)) {
            $cutoff = null;
        }
        $settings['cutoffHour'] = $cutoff;

        // THE DOCUMENT RATE IS GONE (owner, 2026-09-27) and this is where a
        // stored one is let go of rather than tripped over. Every version
        // published before that date carries `settings.docMaxKg` and a `doc`
        // price on most rates; dropping them HERE - not in the value objects
        // alone - means a restored v1 migrates, renders and republishes without
        // the key coming back, and without the schema having to refuse it.
        unset($settings['docMaxKg']);

        if (($settings['pricingMode'] ?? null) !== 'grid') {
            $settings['pricingMode'] = 'slab';
        }

        $company = array_merge(Seed::company(), is_array($input['company'] ?? null) ? $input['company'] : []);
        $content = self::upgradeContent(
            array_merge(Seed::content(), is_array($input['content'] ?? null) ? $input['content'] : [])
        );

        $services = is_array($input['services'] ?? null) && $input['services'] !== []
            ? $input['services']
            : Seed::data()['services'];

        return SiteData::fromArray([
            'live'           => Text::truthy($input['live'] ?? false),
            'company'        => $company,
            'settings'       => $settings,
            'services'       => $services,
            'destinations'   => self::dropDocPrices(
                is_array($input['destinations'] ?? null) ? $input['destinations'] : []
            ),
            'content'        => $content,
            // The promo popup is new; an older document carries no `promo` key
            // and `Promo::fromArray([])` fills the disabled default, so a
            // restored v1 loads with no popup rather than tripping over one.
            'promo'          => is_array($input['promo'] ?? null) ? $input['promo'] : [],
            // Reviews are newer still; an older document carries no `reviews`
            // key and `Reviews::fromArray([])` fills the empty list, so a
            // restored version loads with no reviews rather than tripping over one.
            'reviews'        => is_array($input['reviews'] ?? null) ? $input['reviews'] : [],
            'importProfiles' => is_array($input['importProfiles'] ?? null) ? $input['importProfiles'] : [],
        ]);
    }

    /**
     * Strip the retired flat document price off every rate.
     *
     * `Pricing\Rate::fromArray()` would ignore the key anyway, so this is belt
     * as much as braces - but it is the belt that makes the drop VISIBLE and
     * testable: a v1 document goes through migrate() and comes out with no
     * `doc` anywhere, which is the promise tests/SiteDoc/MigrateTest.php pins.
     * Anything that is not shaped like a rate is left exactly as found, because
     * Schema is what refuses a malformed document, not this.
     *
     * @param  array<array-key,mixed> $destinations
     * @return array<array-key,mixed>
     */
    private static function dropDocPrices(array $destinations): array
    {
        foreach ($destinations as $i => $dest) {
            if (!is_array($dest) || !is_array($dest['rates'] ?? null)) {
                continue;
            }

            foreach ($dest['rates'] as $serviceId => $rate) {
                if (is_array($rate)) {
                    unset($rate['doc']);
                    $dest['rates'][$serviceId] = $rate;
                }
            }

            $destinations[$i] = $dest;
        }

        return $destinations;
    }
}
