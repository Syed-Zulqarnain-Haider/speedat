<?php

declare(strict_types=1);

namespace Speedat\Domain\Notify;

use Speedat\Core\Money;

/**
 * Every notification the office actually receives, as text.
 *
 * The wording is copied from the TypeScript, character for character including
 * the em dash, because the office recognises these messages by their shape and
 * a mail filter somewhere is almost certainly keyed on the subject line. The
 * sources are:
 *
 *   src/app/(site)/contact/actions.ts       the new-message notification
 *   src/app/api/cron/check-sheets/route.ts  the two daily alerts kept
 *
 * FIVE MESSAGES ARE GONE, NOT MISLAID. The TypeScript also had three notices
 * about an emailed rate sheet (unreadable, needs a column mapping, waiting for
 * review) and two daily alerts about the intake mailbox (nothing arrived today,
 * sheets are waiting for a human). The owner removed the Excel rate-sheet
 * feature from the product on 2026-09-27: there is no mailbox to be silent and
 * no queue to wait in, so the messages are deleted rather than kept for a
 * caller that can never exist. The rates CSV export is unaffected - it sends no
 * email.
 *
 * Pure: no clock, no database, no configuration. Everything variable arrives as
 * an argument, so a test can assert the exact string.
 *
 * ONE PORTING NOTE WORTH KNOWING. The contact notification builds its lines as
 * an array with deliberate "" entries between the header block, the message and
 * the admin link - and then filters every empty string out, blank separators
 * included. The result has no blank lines in it at all. That is what the
 * TypeScript does; it is reproduced rather than tidied, because a notification
 * that reads differently from the one the office has had for a year is a change
 * nobody asked for.
 */
final class OfficeMessages
{
    /**
     * A contact-form message arrived.
     *
     * @param string|null $destName the destination's NAME, never its id
     * @param int|null    $weightG  grams, or null when the customer gave none
     */
    public static function newLead(
        string $name,
        string $phone,
        ?string $destName,
        ?int $weightG,
        string $message,
        int $leadId,
        string $baseUrl,
    ): Notification {
        $lines = [
            'Name: ' . $name,
            'Phone: ' . $phone,
            $destName !== null && $destName !== '' ? 'Destination: ' . $destName : '',
            $weightG !== null && $weightG > 0 ? 'Weight: ' . Money::numberToString($weightG / 1000) . ' kg' : '',
            '',
            $message,
            '',
            'Open in the admin: ' . $baseUrl . '/admin/inbox#lead-' . $leadId,
        ];

        return new Notification(
            subject: 'New message from ' . $name
                . ($destName !== null && $destName !== '' ? " - " . $destName : ''),
            text: implode("\n", array_values(array_filter($lines, static fn (string $l): bool => $l !== ''))),
            tag: 'lead',
        );
    }

    /* ----------------------------------------------- the cron alerts (once) */

    /**
     * The live rates are older than the staleness threshold.
     *
     * The closing sentence is the one deliberate change from the TypeScript,
     * which said "import and publish them". There is nothing to import any
     * more, and an alert that tells the owner to use a feature that no longer
     * exists is an alert he learns to ignore. He keys the 1..25 kg boxes in by
     * hand, so that is what it says.
     *
     * `$publishedOn` ARRIVES ALREADY FORMATTED, and that is the point: this
     * class is pure and holds no clock, so it must not pick a time zone. Every
     * stored instant is UTC and the owner's phone says Asia/Karachi, so a
     * message that helpfully printed the raw UTC date would name a day he
     * remembers as a different one. Domain\Ops\Cron converts once, for every
     * alert in the run, and passes the result in.
     */
    public static function ratesStale(int $ageDays, int $version, string $publishedOn, string $baseUrl): AlertMessage
    {
        return new AlertMessage(
            key: 'rates-stale',
            subject: 'Rates were last published ' . $ageDays . ' days ago',
            text: 'Version ' . $version . ' has been live since ' . $publishedOn
                . '. If newer airline rates exist, key them in and publish at ' . $baseUrl . '/admin.',
        );
    }

    /**
     * Prices have been off the site longer than anyone intended.
     *
     * `$since` is a phrase, not a timestamp - the caller's clock again, and the
     * caller's words when the stored instant cannot be read. An empty one is
     * replaced rather than printed: an earlier version substituted '' for an
     * unknown time and the sentence came out as "put prices on hold at .
     * Customers see", which is an alert about a broken thing arriving looking
     * broken itself.
     */
    public static function holdLong(int $hours, string $by, string $since, string $baseUrl): AlertMessage
    {
        return new AlertMessage(
            key: 'hold-long',
            subject: 'Prices have been off the website for ' . $hours . ' hours',
            text: ($by !== '' ? $by : 'An admin') . ' put prices on hold at '
                . ($since !== '' ? $since : 'an unknown time')
                . '. Customers see the hold message and no prices. Publish the new rates, or press Resume, at '
                . $baseUrl . '/admin.',
        );
    }
}
