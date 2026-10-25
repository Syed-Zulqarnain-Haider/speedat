<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

/**
 * "SH-" and eight characters. Port of `newShipmentId` in src/lib/shipments.ts.
 *
 * TWO PROPERTIES, BOTH DELIBERATE.
 *
 * RANDOM, NOT SEQUENTIAL. /track takes this id and shows a stranger where a
 * parcel is. Sequential ids would let anyone walk from their own shipment to
 * everybody else's; eight characters from a thirty-letter alphabet is about
 * 39 bits, which is not a secret but is far past guessing one at a time
 * through a rate-limited endpoint.
 *
 * NO 0, O, 1, I, L OR U. The id is read down a phone line and written on a
 * parcel by hand. The excluded letters are the ones that come back wrong.
 * (U is out because the alphabet is the one the TypeScript chose and there is
 * no reason to differ; the character set is what makes an id valid.)
 *
 * ONE DIFFERENCE FROM THE TYPESCRIPT, and it is an improvement rather than a
 * port: the TypeScript takes a random byte and reduces it modulo 30, which
 * makes the first sixteen letters of the alphabet very slightly likelier than
 * the last fourteen. random_int() draws uniformly with no such skew, and costs
 * nothing. The format, the alphabet and the length - the parts anything else
 * depends on - are identical.
 */
final class ShipmentId
{
    /** No 0/O/1/I/L/U: the characters that come back wrong over the phone. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const PREFIX = 'SH-';

    public const LENGTH = 8;

    /** The whole id, for validating one that arrived from a request. */
    public const PATTERN = '/^SH-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{8}$/';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = self::PREFIX;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * Normalise something a customer typed: upper case, spaces and dashes
     * dropped, the prefix added back if they left it off.
     *
     * Only ever used for looking a shipment up, never for storing one, so it is
     * allowed to be generous. It returns null rather than a guess when what is
     * left cannot be an id.
     */
    public static function normalise(string $typed): ?string
    {
        $bare = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $typed) ?? '');

        if (str_starts_with($bare, 'SH')) {
            $bare = substr($bare, 2);
        }

        $id = self::PREFIX . $bare;

        return self::isValid($id) ? $id : null;
    }
}
