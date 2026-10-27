<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Request;
use Speedat\Http\Flash;
use Speedat\Http\FormToken;
use Speedat\Tests\TestCase;

/**
 * The two primitives the public form is built on, on their own.
 *
 * They are tested apart from the route because each has a failure mode the
 * route cannot show: a token that verifies when it should not, and a flash that
 * can be minted by somebody who is not this site. Both would be silent - the
 * form would keep working, and the guard would simply have stopped guarding.
 */
final class FormGuardsTest extends TestCase
{
    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    /* ------------------------------------------------------------ the token */

    public function testATokenItIssuesIsOneItAccepts(): void
    {
        $now   = Clock::millis();
        $token = FormToken::issue($now);

        $this->assertSame(FormToken::OK, FormToken::check($token, $now + FormToken::MIN_AGE_MS + 1));
    }

    /** Faster than a person could type is the whole signal. */
    public function testAFormReturnedInstantlyIsTooFast(): void
    {
        $now = Clock::millis();

        $this->assertSame(FormToken::TOO_FAST, FormToken::check(FormToken::issue($now), $now));
        $this->assertSame(FormToken::TOO_FAST, FormToken::check(FormToken::issue($now), $now + FormToken::MIN_AGE_MS - 1));
        $this->assertSame(FormToken::OK, FormToken::check(FormToken::issue($now), $now + FormToken::MIN_AGE_MS));
    }

    /** A tab left open since last week is stale, not an attack - and is told so. */
    public function testAFormOlderThanADayIsExpired(): void
    {
        $now = Clock::millis();

        $this->assertSame(FormToken::OK, FormToken::check(FormToken::issue($now), $now + FormToken::MAX_AGE_MS));
        $this->assertSame(FormToken::EXPIRED, FormToken::check(FormToken::issue($now), $now + FormToken::MAX_AGE_MS + 1));
    }

    /**
     * A clock that runs fast on the client produces a token from the future.
     * That is refused as "too fast", which is the safe direction: the visitor is
     * asked to send again rather than let through on an unverifiable timestamp.
     */
    public function testATokenFromTheFutureIsNotAccepted(): void
    {
        $now = Clock::millis();

        $this->assertSame(FormToken::TOO_FAST, FormToken::check(FormToken::issue($now + 600_000), $now));
    }

    public function testNothingElseIsAccepted(): void
    {
        $now = Clock::millis();

        // Pairs, not a map: a null array key is deprecated on PHP 8.5 (and was
        // only ever the empty string anyway, so "missing" was never tested).
        foreach (
            [
                [null, 'missing'],
                ['', 'empty'],
                ['nonsense', 'not a token'],
                ['1790000000000', 'no signature'],
                ['1790000000000.', 'an empty signature'],
                ['1790000000000.' . str_repeat('0', 32), 'a wrong signature'],
                ['1790000000000.' . str_repeat('z', 32), 'a signature that is not hex'],
                ['12345.' . str_repeat('0', 32), 'a timestamp that is too short'],
                ['abc.' . str_repeat('0', 32), 'a timestamp that is not a number'],
            ] as [$token, $why]
        ) {
            $this->assertSame(FormToken::INVALID, FormToken::check($token, $now), $why);
        }
    }

    /** A token signed with somebody else's key is not one of ours. */
    public function testATokenSignedWithAnotherKeyIsInvalid(): void
    {
        $now      = (string) Clock::millis();
        $foreign  = $now . '.' . substr(hash_hmac('sha256', $now, 'not-our-key'), 0, 32);

        $this->assertSame(FormToken::INVALID, FormToken::check($foreign));
    }

    /* ------------------------------------------------------------ the flash */

    public function testAFlashSurvivesExactlyOneRequest(): void
    {
        $writer = new App(Request::synthetic('POST', '/contact'));
        Flash::put($writer, 'contact_sent');

        $cookie = $writer->cookies->queuedValue(Flash::COOKIE);
        $this->assertNotNull($cookie);

        // The request after it sees it...
        $reader = new App(Request::synthetic('GET', '/contact', [], [], [], [Flash::COOKIE => (string) $cookie]));
        $this->assertTrue(Flash::take($reader, 'contact_sent'));
        // ... and clears it, so a reload is an ordinary page again.
        $this->assertSame('', $reader->cookies->queuedValue(Flash::COOKIE), 'reading a flash must delete it');

        // A request with no cookie sees nothing.
        $this->assertFalse(Flash::take(new App(Request::synthetic('GET', '/contact')), 'contact_sent'));
    }

    /**
     * Only this site can put the site into its thank-you state. Without the
     * signature, any page on the internet could show a visitor a confirmation
     * for a message that was never sent.
     */
    public function testAFlashNobodySignedIsNotAFlash(): void
    {
        $future = Clock::timestamp() + 60;

        $forged = [
            'unsigned'           => $future . '.contact_sent.' . str_repeat('0', 32),
            'no signature'       => $future . '.contact_sent',
            'a bare name'        => 'contact_sent',
            'signed but expired' => (Clock::timestamp() - 10) . '.contact_sent.' . str_repeat('0', 32),
        ];

        foreach ($forged as $why => $value) {
            $app = new App(Request::synthetic('GET', '/contact', [], [], [], [Flash::COOKIE => $value]));

            $this->assertFalse(Flash::take($app, 'contact_sent'), $why);
        }
    }

    /** A flash is answered only to the name that was set, and only to a known one. */
    public function testAFlashIsNotAnsweredUnderAnotherName(): void
    {
        $writer = new App(Request::synthetic('POST', '/contact'));
        Flash::put($writer, 'contact_sent');
        $cookie = (string) $writer->cookies->queuedValue(Flash::COOKIE);

        $reader = new App(Request::synthetic('GET', '/contact', [], [], [], [Flash::COOKIE => $cookie]));
        $this->assertFalse(Flash::take($reader, 'something_else'));

        // An unknown name cannot be written either, so nothing can be smuggled
        // into the cookie by a caller.
        $other = new App(Request::synthetic('POST', '/contact'));
        Flash::put($other, 'admin_is_god');
        $this->assertNull($other->cookies->queuedValue(Flash::COOKIE));
    }
}
