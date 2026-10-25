<?php

declare(strict_types=1);

namespace Speedat\Tests\Quotes;

use Speedat\Domain\Pricing\RateCard;

/**
 * The rate card the quote tests price against.
 *
 * Deliberately plain slab pricing with round numbers, so every expected price
 * in a test can be worked out by hand in the comment beside it. A test whose
 * expected value came out of the code it is testing proves nothing.
 *
 *   first 0.5 kg      PKR 4,500
 *   each +0.5 kg      PKR 1,200
 *   tax               none
 *   rounding          to the nearest 10
 *   cargo threshold   70 kg
 *   add-on            "Pickup and service charges", PKR 500
 */
final class Fixtures
{
    public const CURRENCY = 'PKR';

    public static function card(): RateCard
    {
        return RateCard::fromArray([
            'services' => [
                ['id' => 'express', 'name' => 'Express'],
                ['id' => 'normal', 'name' => 'Normal'],
            ],
            'destinations' => [
                [
                    'id'     => 'ca',
                    'name'   => 'Canada',
                    'active' => true,
                    'rates'  => [
                        'express' => ['first' => 4500, 'addl' => 1200, 'days' => '3-5'],
                        'normal'  => ['first' => 3500, 'addl' => 900, 'days' => '6-9'],
                    ],
                ],
                [
                    // Hidden: quoting to it must be refused exactly as if it did
                    // not exist.
                    'id'     => 'zz',
                    'name'   => 'Nowhere',
                    'active' => false,
                    'rates'  => ['express' => ['first' => 1000, 'addl' => 100]],
                ],
            ],
            'settings' => [
                'pricingMode'       => 'slab',
                'currency'          => self::CURRENCY,
                'volumetricDivisor' => 5000,
                'firstKg'           => 0.5,
                'stepKg'            => 0.5,
                'taxPct'            => 0,
                'roundTo'           => 10,
                'maxKg'             => 70,
                'addons'            => "Pickup and service charges | 500 | on\nInsurance | 250 | off",
            ],
        ]);
    }

    /** A quote reference that is valid and unique to this run. */
    public static function quoteId(): string
    {
        $tail = '';
        $pool = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < 4; $i++) {
            $tail .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return 'SP-990101-' . $tail;
    }

    /**
     * A well-formed request body, which each test then spoils in one specific
     * way. Keeping the honest body in one place means a test reads as "this
     * field, changed to this" and nothing else.
     *
     * No `type`: the document rate left the product on 2026-09-27 and the
     * browser no longer sends one. A test that wants to prove a stale body is
     * still accepted adds the key back itself.
     *
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function body(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id'         => $id,
            'destId'     => 'ca',
            'serviceId'  => 'express',
            'chargeG'    => 1000,
            'billableG'  => 1000,
            'total'      => 5700,
            'version'    => 7,
            'piecesText' => '1 x 1 kg',
            'booked'     => false,
        ];
    }
}
