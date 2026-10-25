<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Response;
use Speedat\Http\Flash;
use Speedat\Http\FormToken;

/**
 * POST /contact - every way it can be refused, and the one way it works.
 *
 * The guards are the point of this file. A contact form that stores a lead is
 * four lines; a contact form that cannot be driven by a script, cannot be
 * submitted from another site, cannot be replayed by a reload and tells a human
 * what went wrong in a sentence is the rest of it.
 *
 * The rate-limit bucket is keyed on the client address, and every synthetic
 * request comes from 127.0.0.1, so the bucket is cleared around each test -
 * otherwise the first test to send five messages would fail every test after it.
 */
final class ContactFormTest extends HttpTestCase
{
    /** The name every lead in this file is written under, so tearDown can find them. */
    private const WHO = 'Http Test Sender';

    private const GOOD = [
        'name'    => self::WHO,
        'phone'   => '+92 333 766 7076',
        'message' => 'Two boxes of clothes to Canada, about five kilos.',
        'destId'  => 'ca',
        'weight'  => '5',
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Clock::freeze(null);
        $this->clean();
    }

    private function clean(): void
    {
        if (!Db::healthy()) {
            return;
        }

        Db::exec("DELETE FROM rate_limits WHERE k LIKE 'contact:%'");
        Db::exec('DELETE FROM leads WHERE name = ?', [self::WHO]);
    }

    /** A token minted far enough in the past to have been typed into. */
    private function agedToken(): string
    {
        return FormToken::issue(Clock::millis() - FormToken::MIN_AGE_MS - 3000);
    }

    private function leadCount(): int
    {
        $n = Db::value('SELECT COUNT(*) FROM leads WHERE name = ?', [self::WHO], 0);

        return is_numeric($n) ? (int) $n : 0;
    }

    /* ===================================================================== */
    /*  THE FORM AS IT IS SERVED                                             */
    /* ===================================================================== */

    public function testTheFormIsServedWithItsThreeGuards(): void
    {
        $answer = $this->get('/contact');
        $html   = $answer->body();

        $this->assertStatus(200, $answer);
        $this->assertStringContains('method="post"', $html);
        $this->assertStringContains('name="_token" value="', $html, 'the CSRF token');
        $this->assertStringContains('name="t" value="', $html, 'the render token');
        $this->assertStringContains('name="website"', $html, 'the honeypot');

        $guards = $this->contactGuards();
        $this->assertNotSame('', $guards['token'], 'a CSRF token was rendered');
        $this->assertSame(FormToken::TOO_FAST, FormToken::check($guards['form']), 'the render token is this instant');
    }

    /* ===================================================================== */
    /*  REFUSALS                                                             */
    /* ===================================================================== */

    /**
     * No token, no message. This is the check that stops another site posting
     * through a visitor's browser.
     *
     * 403 AND NOT 419, AND THIS IS THE BRANCH THE GO-LIVE REHEARSAL CAUGHT. On
     * real Apache, `POST /contact` with no token answered **500 Internal Server
     * Error** while the page below it was the right page with the right
     * sentence on it - because 419 is not a registered status, so Apache has no
     * reason phrase for it and its lookup falls back to the 500 slot. Every
     * expired contact form on the live site would have been counted as the site
     * falling over. `Core\HttpError::invalidToken()` carries the measurement.
     */
    public function testAPostWithNoCsrfTokenIsRefusedAndStoresNothing(): void
    {
        $before = $this->leadCount();
        $answer = $this->post('/contact', self::GOOD + ['t' => $this->agedToken()]);

        $this->assertStatus(403, $answer);
        $this->assertStringContains('This form expired', $this->formNotice($answer));
        $this->assertSame($before, $this->leadCount(), 'a refused post wrote a lead');
    }

    /**
     * THE PROPERTY THE STATUS HAS TO HAVE, stated as itself rather than as a
     * number.
     *
     * A number in an assertion says what was chosen; it does not say why, and
     * the next person is free to choose another. What the expired contact form
     * needs is a status every web server can put on the wire AS ITSELF - the
     * one thing 419 was not - so that is what is asserted here, against the
     * table in `Core\Response`, on the real answer to a real stale post.
     */
    public function testTheExpiredFormAnswersAStatusAServerCanActuallySend(): void
    {
        $answer = $this->post('/contact', self::GOOD + ['t' => $this->agedToken()]);

        $this->assertTrue(
            Response::isTransportable($answer->response->status),
            'the expired contact form answers ' . $answer->response->status
            . ', which no server has a reason phrase for - Apache sends it as a 500'
        );
        $this->assertSame(
            null,
            Response::statusLine($answer->response->status),
            'this status needs a hand-written status line, which LiteSpeed would drop'
        );
    }

    public function testAForgedCsrfTokenIsRefused(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post(
            '/contact',
            self::GOOD + ['t' => $this->agedToken(), '_token' => '1790000000.abcdefghijkl.' . str_repeat('0', 32)],
            [],
            $guards['cookies'],
        );

        $this->assertStatus(403, $answer);
    }

    /**
     * A cross-site submission carries the attacker's Origin, which is the one
     * thing about it they cannot choose.
     */
    public function testAPostFromAnotherOriginIsRefused(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post(
            '/contact',
            self::GOOD + ['t' => $this->agedToken(), '_token' => $guards['token']],
            ['origin' => 'https://evil.example'],
            $guards['cookies'],
        );

        $this->assertStatus(403, $answer);
        $this->assertSame(0, $this->leadCount());
    }

    /** Filled in by hand in under three seconds means it was not filled in by hand. */
    public function testAFormSubmittedTooFastIsRefusedPolitely(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post(
            '/contact',
            self::GOOD + ['_token' => $guards['token'], 't' => $guards['form']],
            [],
            $guards['cookies'],
        );

        $this->assertStatus(422, $answer);
        $this->assertStringContains('That was quick', $this->formNotice($answer));
        $this->assertSame(0, $this->leadCount());
    }

    public function testAMissingOrStaleRenderTokenSaysToReloadThePage(): void
    {
        $guards = $this->contactGuards();

        foreach (['' => 'missing', 'nonsense' => 'malformed', '1500000000000.' . str_repeat('a', 32) => 'unsigned'] as $token => $why) {
            $answer = $this->post(
                '/contact',
                self::GOOD + ['_token' => $guards['token'], 't' => (string) $token],
                [],
                $guards['cookies'],
            );

            $this->assertStatus(422, $answer, $why);
            $this->assertStringContains('Reload it and send again', $this->formNotice($answer), $why);
        }
    }

    /**
     * Every field rule, with the sentence the visitor reads, and what they typed
     * still in the form.
     */
    public function testBadFieldsComeBackWithReasonsAndWhatWasTyped(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post('/contact', [
            'name'    => 'A',
            'phone'   => 'call me maybe',
            'message' => 'hi',
            '_token'  => $guards['token'],
            't'       => $this->agedToken(),
        ], [], $guards['cookies']);

        $this->assertStatus(422, $answer);
        $this->assertSame([
            'Please tell us your name',
            'Enter a phone number we can call or WhatsApp',
            'Tell us what you would like to send',
        ], $this->fieldErrors($answer));

        $this->assertStringContains('value="A"', $answer->body(), 'the name was emptied');
        $this->assertStringContains('value="call me maybe"', $answer->body(), 'the phone was emptied');
        $this->assertStringContains('>hi</textarea>', $answer->body(), 'the message was emptied');
        $this->assertSame(0, $this->leadCount());
    }

    public function testAnOverlongNameAndMessageAreRefusedWithTheirOwnSentences(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post('/contact', [
            'name'    => str_repeat('a', 130),
            'phone'   => '+92 333 766 7076',
            'message' => str_repeat('b', 2500),
            '_token'  => $guards['token'],
            't'       => $this->agedToken(),
        ], [], $guards['cookies']);

        $this->assertStatus(422, $answer);
        $this->assertSame([
            'That name is too long',
            'Please keep the message under 2000 characters',
        ], $this->fieldErrors($answer));
        $this->assertSame(0, $this->leadCount());
    }

    /**
     * A bot that fills the hidden field is told the message was sent and nothing
     * is stored. Telling it that it was caught only teaches whoever wrote it
     * which field to leave alone.
     */
    public function testTheHoneypotPretendsToSucceedAndStoresNothing(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post('/contact', self::GOOD + [
            '_token'  => $guards['token'],
            't'       => $this->agedToken(),
            'website' => 'http://spam.example',
        ], [], $guards['cookies']);

        $this->assertStatus(200, $answer);
        $this->assertStringContains('class="card contact-done"', $answer->body(), 'it did not look like a success');
        $this->assertStringNotContains('<form', $answer->body());
        $this->assertSame(0, $this->leadCount(), 'the honeypot stored a lead');
        $this->assertNull($answer->cookie(Flash::COOKIE), 'a bot needs no flash cookie');
    }

    /* ===================================================================== */
    /*  THE ONE WAY THROUGH                                                  */
    /* ===================================================================== */

    /**
     * Post, redirect, get: the lead is stored, the response is a 303 to the
     * page, and the thank-you is drawn by the GET from a one-shot cookie. A
     * reload of that GET is an ordinary contact page, so one submit is one
     * message however many times the browser repeats itself.
     */
    public function testAGoodMessageIsStoredAndTheThankYouIsAGet(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $guards = $this->contactGuards();

        $posted = $this->post(
            '/contact',
            self::GOOD + ['_token' => $guards['token'], 't' => $this->agedToken()],
            [],
            $guards['cookies'],
        );

        $this->assertStatus(303, $posted);
        $this->assertSame('/contact', $posted->header('Location'));
        $this->assertSame('', $posted->body(), 'a redirect carries no page');

        $flash = $posted->cookie(Flash::COOKIE);
        $this->assertNotNull($flash, 'no flash was queued, so the GET cannot know');

        // The lead, as the office will read it.
        $lead = Db::one('SELECT kind, name, phone, message, dest_id, weight_g, ip_hash FROM leads WHERE name = ?', [self::WHO]);
        $this->assertNotNull($lead);
        $this->assertSame('message', $lead['kind'] ?? null);
        $this->assertSame(self::GOOD['phone'], $lead['phone'] ?? null);
        $this->assertSame(self::GOOD['message'], $lead['message'] ?? null);
        $this->assertSame('ca', $lead['dest_id'] ?? null, 'the destination was matched against the live document');
        $this->assertSame(5000, isset($lead['weight_g']) ? (int) $lead['weight_g'] : null, '5 kg in grams');
        // The address is hashed, never stored.
        $this->assertTrue(preg_match('/^[0-9a-f]{32}$/', (string) ($lead['ip_hash'] ?? '')) === 1, 'the IP was not hashed');

        // The GET that follows.
        $thanks = $this->get('/contact', [], [], [Flash::COOKIE => (string) $flash]);
        $this->assertStatus(200, $thanks);
        $this->assertStringContains('class="card contact-done"', $thanks->body());
        $this->assertStringNotContains('<form', $thanks->body(), 'the form is still on the thank-you page');
        $this->assertSame('', (string) $thanks->cookie(Flash::COOKIE), 'reading the flash must clear it');

        // ... and a reload, without the cookie the response just cleared.
        $reload = $this->get('/contact');
        $this->assertStringNotContains('contact-done', $reload->body(), 'the thank-you survived the reload');
        $this->assertStringContains('<form', $reload->body());
    }

    /** An unknown destination is "no destination", not a refusal and not a stored id. */
    public function testAnUnknownDestinationIsSimplyNotRecorded(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable');
        }

        $guards = $this->contactGuards();

        $this->post('/contact', array_merge(self::GOOD, [
            'destId' => 'atlantis',
            'weight' => 'about five',
            '_token' => $guards['token'],
            't'      => $this->agedToken(),
        ]), [], $guards['cookies']);

        $lead = Db::one('SELECT dest_id, weight_g FROM leads WHERE name = ?', [self::WHO]);
        $this->assertNotNull($lead);
        // Read the columns directly, not through `??`: a NULL column and a
        // missing key are the same thing to `??`, so an assertion written that
        // way passes whether or not the column was selected at all.
        $this->assertNull($lead['dest_id'], 'an id the document does not have was stored');
        $this->assertNull($lead['weight_g'], 'a weight nobody typed was invented');
    }

    /** Nobody can put the site into its thank-you state but the site. */
    public function testAForgedFlashCookieDoesNotShowTheThankYou(): void
    {
        $forged = (Clock::timestamp() + 60) . '.contact_sent.' . str_repeat('0', 32);

        $answer = $this->get('/contact', [], [], [Flash::COOKIE => $forged]);

        $this->assertStatus(200, $answer);
        $this->assertStringNotContains('contact-done', $answer->body());
        $this->assertStringContains('<form', $answer->body());
    }

    /* ===================================================================== */
    /*  THE LIMIT                                                            */
    /* ===================================================================== */

    /**
     * Five messages per quarter of an hour per address, then a sentence that
     * offers WhatsApp instead - and a `Retry-After` for anything automated.
     */
    public function testTheSixthMessageFromOneAddressIsRefused(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - the rate limiter fails open without it');
        }

        $guards = $this->contactGuards();
        $last   = null;

        for ($i = 1; $i <= 6; $i++) {
            $answer = $this->post(
                '/contact',
                self::GOOD + ['_token' => $guards['token'], 't' => $this->agedToken()],
                [],
                $guards['cookies'],
            );

            if ($i <= 5) {
                $this->assertStatus(303, $answer, 'message ' . $i);
                continue;
            }

            $last = $answer;
        }

        $this->assertNotNull($last);
        $this->assertStatus(429, $last);
        $this->assertStringContains('Too many messages from this connection', $this->formNotice($last));
        $this->assertStringContains('WhatsApp us', $this->formNotice($last));
        $this->assertNotSame('', $last->header('Retry-After'), 'a 429 without Retry-After is a 429 nobody can obey');
        $this->assertSame(5, $this->leadCount(), 'the refused message was stored anyway');
    }
}
