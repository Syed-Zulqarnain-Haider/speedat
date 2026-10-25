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
 *
 * THE OTHER THREE WAYS PEOPLE WRITE THE SAME NUMBER (fixed 2026-10-25). Each
 * passes the contact form's pattern, and each used to become a link that rang
 * nobody:
 *
 *   "0092 300 1234567"    the 00 international prefix. Read as a 0-number it
 *                         became 920923001234567.
 *   "+92 (0) 300 1234567" the trunk zero kept after the country code. It
 *                         became 9203001234567. No Pakistani area code or
 *                         mobile prefix starts with 0, so "920" is always this
 *                         mistake and never a real number.
 *   "300 1234567"         a mobile number with neither 0 nor 92: ten digits
 *                         starting 3, which is how a caller reads it out.
 *
 * The rules run in that order and then the 03xx rule, so a number written
 * any of these ways reaches the same 923xx the office would have typed.
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

        if (str_starts_with($digits, '00')) {
            // Measured again without the prefix: "00" and six digits is still
            // not a number anyone can be reached on.
            $digits = substr($digits, 2);

            if (strlen($digits) < 8) {
                return '';
            }
        }

        if (str_starts_with($digits, '920')) {
            $digits = '92' . substr($digits, 3);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = '92' . $digits;
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
