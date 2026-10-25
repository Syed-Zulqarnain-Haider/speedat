<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * WhatsApp links for a customer's number, as the inbox and the shipment desk
 * build them. Port of the `waHref` helpers in InboxList.tsx and
 * ShipmentDesk.tsx.
 *
 * A local Pakistani number typed as 03xx becomes 923xx, because that is how
 * the office types it and wa.me only accepts the international form. Anything
 * shorter than eight digits is not a phone number and gets no link, rather
 * than a link that opens WhatsApp on nothing.
 */
final class Wa
{
    /** The international digits, or "" when the number cannot be one. */
    public static function digits(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (strlen($digits) < 8) {
            return '';
        }

        return str_starts_with($digits, '0') ? '92' . substr($digits, 1) : $digits;
    }

    /** `https://wa.me/923001234567`, or "" when there is no usable number. */
    public static function href(string $phone): string
    {
        $digits = self::digits($phone);

        return $digits === '' ? '' : 'https://wa.me/' . $digits;
    }

    /** The same link carrying a prepared message. */
    public static function hrefWithText(string $phone, string $text): string
    {
        $digits = self::digits($phone);

        return $digits === '' ? '' : 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
    }
}
