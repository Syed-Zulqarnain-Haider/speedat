<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

/**
 * The quote reference: "SP-260920-K7QX".
 *
 * SP, then the date as YYMMDD, then four characters of A-Z and 0-9. It is
 * date-stamped so the office can see at a glance how old a reference is, and
 * short enough to read down the phone.
 *
 * WHO MAKES ONE. Normally the browser does, with
 * Speedat\Domain\Pricing\Format::quoteId() and its JavaScript twin, because the
 * customer's WhatsApp message carries the reference and the message is composed
 * before anything is posted. This class validates the one that arrives and can
 * mint a replacement for a quote created inside the admin, where there is no
 * browser-side id to honour.
 *
 * VALIDATION IS EXACT, not a length check. The id is the primary key of the
 * quotes table and the unique key one lead hangs off; a "reference" that is
 * really 200 bytes of anything is how a log table becomes a storage endpoint.
 * The column is 32 characters and a valid id is 14, so anything that passes
 * here fits with room to spare.
 */
final class QuoteId
{
    /** The same expression the TypeScript route validates with. */
    public const PATTERN = '/^SP-\d{6}-[A-Z0-9]{4}$/';

    /** quotes.id is VARCHAR(32); a valid id is 14. */
    public const MAX_LENGTH = 32;

    private const TAIL = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * Validate one that arrived from a request.
     *
     * Not called `require()`: PHP does allow a keyword as a method name, but a
     * line reading `QuoteId::require($id)` next to a real `require` is a line
     * somebody will misread.
     *
     * THE REFUSAL SAYS NOTHING ABOUT THE ID. It used to answer "That is not a
     * quote reference." while an unknown destination answered a different
     * sentence with a different status again, so posting bodies at
     * `/api/quotes` told a stranger WHICH field had failed. The reference is
     * the caller's own, so the sentence taught it nothing it did not already
     * know - but the endpoint's stated bar is that probing teaches nothing,
     * and a bar that holds for two checks out of three is not a bar. This is
     * now the same "Invalid quote" every other field gets.
     *
     * @throws QuoteRejected
     */
    public static function parse(string $id): string
    {
        $id = trim($id);

        if (!self::isValid($id)) {
            throw QuoteRejected::badRequest();
        }

        return $id;
    }

    /**
     * A fresh id for a quote the admin created.
     *
     * The date is taken in the display time zone, not UTC, because the office
     * reads "260920" as "the twentieth of September" in Lahore and a reference
     * stamped with yesterday's date for the first five hours of every day is a
     * reference nobody trusts.
     */
    public static function generate(\DateTimeImmutable $now): string
    {
        $tail = '';
        for ($i = 0; $i < 4; $i++) {
            $tail .= self::TAIL[random_int(0, strlen(self::TAIL) - 1)];
        }

        return 'SP-' . $now->format('ymd') . '-' . $tail;
    }
}
