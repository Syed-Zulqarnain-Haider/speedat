<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Domain\Notify\OfficeMessages;
use Speedat\Tests\TestCase;

/**
 * The wording of every notification, against the TypeScript it came from.
 *
 * These are compared as whole strings on purpose. The office recognises these
 * messages by their shape and there is almost certainly a mail filter somewhere
 * keyed on a subject line, so "close enough" is not close enough - a changed
 * word here is a notification that stops being filed.
 *
 * Sources: src/app/(site)/contact/actions.ts and
 * src/app/api/cron/check-sheets/route.ts.
 *
 * The five rate-sheet notices the TypeScript also had are not tested here
 * because they no longer exist: the owner removed the Excel rate-sheet feature
 * from the product on 2026-09-27. The last test in this file is the guard that
 * they do not come back by accident.
 */
final class OfficeMessagesTest extends TestCase
{
    private const BASE = 'https://speedat.com';

    /* ------------------------------------------------------- a new message */

    public function testTheNewMessageNotificationIsWordForWord(): void
    {
        $mail = OfficeMessages::newLead(
            'Ayesha Khan',
            '0300 1234567',
            'Canada',
            4500,
            "I want to send books to my son.\nAbout 4.5 kg.",
            412,
            self::BASE,
        );

        $this->assertSame("New message from Ayesha Khan \u{2014} Canada", $mail->subject);
        $this->assertSame('lead', $mail->tag);

        $this->assertSame(
            implode("\n", [
                'Name: Ayesha Khan',
                'Phone: 0300 1234567',
                'Destination: Canada',
                'Weight: 4.5 kg',
                'I want to send books to my son.',
                'About 4.5 kg.',
                'Open in the admin: https://speedat.com/admin/inbox#lead-412',
            ]),
            $mail->text,
        );
    }

    /**
     * The blank separators in the TypeScript are filtered out along with the
     * absent fields, so the message has no blank lines in it. That is what the
     * office has been receiving; it is reproduced rather than tidied.
     */
    public function testWithNoDestinationOrWeightTheLinesAreSimplyAbsent(): void
    {
        $mail = OfficeMessages::newLead('Bilal', '03211234567', null, null, 'Two boxes, please call.', 9, self::BASE);

        $this->assertSame('New message from Bilal', $mail->subject);
        $this->assertSame(
            implode("\n", [
                'Name: Bilal',
                'Phone: 03211234567',
                'Two boxes, please call.',
                'Open in the admin: https://speedat.com/admin/inbox#lead-9',
            ]),
            $mail->text,
        );
        $this->assertStringNotContains("\n\n", $mail->text, 'there are no blank lines');
    }

    public function testAWholeNumberOfKilogramsPrintsWithoutADecimalPoint(): void
    {
        $this->assertStringContains(
            "\nWeight: 2 kg\n",
            OfficeMessages::newLead('A', '1', null, 2000, 'x', 1, self::BASE)->text,
        );
        $this->assertStringContains(
            "\nWeight: 0.25 kg\n",
            OfficeMessages::newLead('A', '1', null, 250, 'x', 1, self::BASE)->text,
        );
    }

    public function testTheHtmlFallbackIsAPreOfTheTextWithTheFourCharactersEscaped(): void
    {
        $mail = OfficeMessages::newLead('A & B <script>', '1', null, null, 'He said "hi" & left', 1, self::BASE);

        $html = $mail->htmlBody();

        $this->assertStringContains('<pre style="font-family:sans-serif;white-space:pre-wrap">', $html);
        $this->assertStringContains('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContains('&quot;hi&quot;', $html);
        $this->assertStringNotContains('<script>', $html);
    }

    /* ---------------------------------------------------------- cron alerts */

    public function testTheStaleRatesAlert(): void
    {
        $alert = OfficeMessages::ratesStale(9, 41, '2026-09-18', self::BASE);

        $this->assertSame('rates-stale', $alert->key);
        $this->assertSame('Rates were last published 9 days ago', $alert->subject);
        $this->assertSame(
            'Version 41 has been live since 2026-09-18. If newer airline rates exist, key them in and publish at https://speedat.com/admin.',
            $alert->text,
        );
        $this->assertStringNotContains('import', $alert->text, 'there is nothing to import any more');
    }

    public function testTheLongHoldAlertNamesWhoLeftItOnOrSaysAnAdmin(): void
    {
        $named = OfficeMessages::holdLong(18, 'owner@speedat.com', '2026-09-26 14:20', self::BASE);

        $this->assertSame('hold-long', $named->key);
        $this->assertSame('Prices have been off the website for 18 hours', $named->subject);
        $this->assertSame(
            'owner@speedat.com put prices on hold at 2026-09-26 14:20. Customers see the hold message and no prices. Publish the new rates, or press Resume, at https://speedat.com/admin.',
            $named->text,
        );

        $anonymous = OfficeMessages::holdLong(18, '', '2026-09-26 14:20', self::BASE);
        $this->assertStringContains('An admin put prices on hold at', $anonymous->text);
    }

    /**
     * THE TIMES ARE THE CALLER'S WORDS, and a missing one is still a sentence.
     *
     * Both alerts used to take an ISO-8601 string and cut a date out of it,
     * which meant a class with no clock was quietly choosing UTC for a reader
     * whose phone is five hours ahead of it. Domain\Ops\Cron converts now, and
     * the case that used to read badly - a stored instant nothing can parse -
     * gets words instead of a gap.
     */
    public function testAnUnknownHoldTimeStillReadsAsEnglish(): void
    {
        $unknown = OfficeMessages::holdLong(18, 'owner@speedat.com', '', self::BASE);

        $this->assertStringContains('put prices on hold at an unknown time. Customers see', $unknown->text);
        $this->assertStringNotContains(' at . ', $unknown->text, 'never a gap where the time should be');
    }

    /** Whatever the caller formatted is what the office reads, zone and all. */
    public function testTheCallersFormattingIsPrintedUnchanged(): void
    {
        $this->assertStringContains(
            'has been live since 18 Sep 2026',
            OfficeMessages::ratesStale(9, 41, '18 Sep 2026', self::BASE)->text,
        );
        $this->assertStringContains(
            'on hold at 2026-09-26 19:20 PKT',
            OfficeMessages::holdLong(18, 'a@b.test', '2026-09-26 19:20 PKT', self::BASE)->text,
        );
    }

    /* ------------------------------------------------ the removed notices */

    /**
     * The Excel rate-sheet feature is out of the product (owner, 2026-09-27),
     * and this class is where its five notices used to live. Dead messages have
     * a way of coming back with the caller that resurrects them, so the absence
     * is asserted rather than assumed.
     */
    public function testTheRateSheetNoticesAreGone(): void
    {
        foreach ([
            'sheetUnreadable',
            'sheetNeedsMapping',
            'sheetWaitingReview',
            'sheetMissing',
            'sheetsPending',
        ] as $gone) {
            $this->assertFalse(
                method_exists(OfficeMessages::class, $gone),
                'OfficeMessages::' . $gone . '() belongs to a feature that was removed'
            );
        }

        // And nothing that is left says "sheet" or "import" to the office.
        $everything = OfficeMessages::newLead('A', '1', 'Canada', 2000, 'x', 1, self::BASE)->text
            . OfficeMessages::ratesStale(9, 41, '2026-09-18', self::BASE)->text
            . OfficeMessages::holdLong(18, 'a@b.test', '2026-09-26 14:20', self::BASE)->text;

        foreach (['sheet', 'Sheet', 'Excel', 'xlsx', 'import', 'Import', 'mapping', 'intake'] as $word) {
            $this->assertStringNotContains($word, $everything, '"' . $word . '" reached the office');
        }
    }
}
