<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Tests\TestCase;
use Speedat\Vendor\Smtp\Message;
use Speedat\Vendor\Smtp\SmtpException;

/**
 * The message itself: the headers, the quoted-printable body, and the things it
 * refuses to be built out of.
 *
 * The refusals are the important half. A notification subject contains a
 * customer's name, straight from a public form; a CR or LF in a header value is
 * how "New message from Ayesha" becomes an extra Bcc line.
 */
final class MessageTest extends TestCase
{
    private const FROM   = 'noreply@speedat.test';
    private const OFFICE = 'office@speedat.test';

    public function testTheHeadersAreTheOnesAMailServerExpects(): void
    {
        $message = Message::create(
            self::FROM,
            'Speedat International',
            self::OFFICE,
            'New message from Ayesha Khan',
            'Body',
            null,
            null,
            'lead',
            'speedat.test',
            new \DateTimeImmutable('2026-09-27 08:05:00', new \DateTimeZone('UTC')),
        );

        $headers = $message->headers();

        $this->assertSame('Sun, 27 Sep 2026 08:05:00 +0000', $headers['Date']);
        $this->assertSame('Speedat International <noreply@speedat.test>', $headers['From']);
        $this->assertSame('office@speedat.test', $headers['To']);
        $this->assertSame('New message from Ayesha Khan', $headers['Subject']);
        $this->assertSame('1.0', $headers['MIME-Version']);
        $this->assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame('quoted-printable', $headers['Content-Transfer-Encoding']);
        $this->assertSame('auto-generated', $headers['Auto-Submitted']);
        $this->assertSame('lead', $headers['X-Speedat-Tag']);

        // The Message-ID is a unique token at the site's own domain, in angle
        // brackets, which is what stops some filters scoring the message.
        $this->assertTrue(
            preg_match('/^<[0-9a-f]{32}@speedat\.test>$/', $headers['Message-ID']) === 1,
            'unexpected Message-ID: ' . $headers['Message-ID'],
        );
    }

    public function testTwoMessagesNeverShareAMessageId(): void
    {
        $a = Message::create(self::FROM, '', self::OFFICE, 'One', 'x');
        $b = Message::create(self::FROM, '', self::OFFICE, 'One', 'x');

        $this->assertNotSame($a->messageId, $b->messageId);
    }

    public function testUtf8SurvivesTheBodyAndTheSubject(): void
    {
        $message = Message::create(
            self::FROM,
            'Speedat',
            self::OFFICE,
            "Express to Canada \u{2014} 5 kg",
            "Total: PKR 19,620 \u{00b7} 1 \u{00d7} 5 kg",
        );

        // A non-ASCII subject is RFC 2047 encoded; the body is quoted-printable.
        $this->assertSame(
            '=?UTF-8?B?' . base64_encode("Express to Canada \u{2014} 5 kg") . '?=',
            $message->headers()['Subject'],
        );
        $this->assertSame(
            "Total: PKR 19,620 =C2=B7 1 =C3=97 5 kg",
            $message->body(),
        );
        $this->assertSame(
            "Total: PKR 19,620 \u{00b7} 1 \u{00d7} 5 kg",
            quoted_printable_decode($message->body()),
            'the body does not survive a round trip',
        );
    }

    public function testAnAsciiSubjectIsLeftReadable(): void
    {
        $message = Message::create(self::FROM, '', self::OFFICE, 'No rate sheet received today by 14:00', 'x');

        $this->assertSame('No rate sheet received today by 14:00', $message->headers()['Subject']);
    }

    public function testEveryBodyLineStaysInsideTheProtocolLimit(): void
    {
        $message = Message::create(self::FROM, '', self::OFFICE, 'Long', str_repeat('abcdefghij ', 40));

        foreach (explode("\r\n", $message->data()) as $line) {
            $this->assertTrue(strlen($line) <= 998, 'a line was ' . strlen($line) . ' octets long');
        }
    }

    public function testNoLineFeedIsLeftBareOnTheWire(): void
    {
        $message = Message::create(self::FROM, '', self::OFFICE, 'Mixed', "unix\nmac\rwindows\r\nend");

        // Every LF must be preceded by a CR: a bare line feed is what strict
        // servers reject outright.
        $this->assertSame(
            0,
            preg_match_all('/(?<!\r)\n/', $message->data()),
            'a bare line feed reached the message body',
        );
    }

    public function testAnHtmlAlternativeBecomesMultipartWithBothParts(): void
    {
        $message = Message::create(self::FROM, '', self::OFFICE, 'Both', 'plain text', '<p>rich text</p>');

        $this->assertStringContains('multipart/alternative; boundary="', $message->headers()['Content-Type']);
        $this->assertStringContains('Content-Type: text/plain; charset=UTF-8', $message->body());
        $this->assertStringContains('Content-Type: text/html; charset=UTF-8', $message->body());
        $this->assertStringContains('plain text', $message->body());
        $this->assertStringContains('<p>rich text</p>', $message->body());
    }

    /* ===================================================================== */
    /*  refusals                                                             */
    /* ===================================================================== */

    public function testALineBreakInAnAddressIsRefused(): void
    {
        foreach (
            [
                "office@speedat.test\r\nBcc: victim@example.com",
                "office@speedat.test\nBcc: victim@example.com",
            ] as $injected
        ) {
            $e = $this->assertThrows(
                SmtpException::class,
                static fn () => Message::create(self::FROM, '', $injected, 'Hi', 'x'),
                'accepted an address with a line break in it',
            );
            $this->assertStringContains('line break', $e->getMessage());
        }
    }

    /**
     * A line break in the SUBJECT is flattened, not refused - the subject comes
     * from a customer's own words and rejecting their message would be worse
     * than tidying it. What matters is that it cannot become a second header.
     */
    public function testALineBreakInTheSubjectIsFlattenedToASpace(): void
    {
        $message = Message::create(
            self::FROM,
            '',
            self::OFFICE,
            "New message\r\nBcc: victim@example.com",
            'x',
        );

        $this->assertSame('New message Bcc: victim@example.com', $message->headers()['Subject']);
        $this->assertSame(
            1,
            substr_count($message->data(), 'Subject:'),
            'the subject turned into two headers',
        );
    }

    public function testALineBreakInTheDisplayNameCannotBecomeAHeader(): void
    {
        $message = Message::create(
            self::FROM,
            "Speedat\r\nBcc: victim@example.com",
            self::OFFICE,
            'Hi',
            'x',
        );

        $this->assertStringNotContains("\r\nBcc:", $message->data());
    }

    public function testAnUnusableAddressOrEmptySubjectIsRefused(): void
    {
        $this->assertThrows(SmtpException::class, static fn () => Message::create(self::FROM, '', 'not-an-address', 'Hi', 'x'));
        $this->assertThrows(SmtpException::class, static fn () => Message::create('not-an-address', '', self::OFFICE, 'Hi', 'x'));
        $this->assertThrows(SmtpException::class, static fn () => Message::create(self::FROM, '', self::OFFICE, '   ', 'x'));
        $this->assertThrows(SmtpException::class, static fn () => Message::create(self::FROM, '', [], 'Hi', 'x'));
    }

    public function testAnInventedTagIsRefusedRatherThanPutInAHeader(): void
    {
        $this->assertThrows(
            SmtpException::class,
            static fn () => Message::create(self::FROM, '', self::OFFICE, 'Hi', 'x', null, null, "lead\r\nBcc: v@e.com"),
        );
    }

    public function testSeveralRecipientsAreAllowedAndAllAppearInTheEnvelope(): void
    {
        $message = Message::create(self::FROM, '', [self::OFFICE, 'owner@speedat.test'], 'Hi', 'x');

        $this->assertSame(2, count($message->to));
        $this->assertSame('office@speedat.test, owner@speedat.test', $message->headers()['To']);
    }
}
