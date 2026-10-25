<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\Session;
use Speedat\View\Admin\Routes;

/**
 * The door. Every statement here is about what the admin REFUSES.
 *
 * The four properties, and the reason each is a test rather than a code review:
 *
 *   1. NO ADMIN PAGE ANSWERS 200 WITHOUT A SESSION. Not the editor, not the
 *      review screen, not the inbox, not the export. A 200 here is the whole
 *      admin on the internet.
 *   2. THE ANSWER IS A REDIRECT, NOT A 403. A 403 tells a stranger which URLs
 *      are real.
 *   3. A POST WITHOUT THE TOKEN IS REFUSED, on every write route. One missing
 *      `Guard::write()` and any page on the internet can publish prices through
 *      a signed-in owner's browser.
 *   4. AN EDITOR CANNOT PUBLISH OR RESUME PRICES, whatever the template shows.
 */
final class AdminGuardTest extends AdminTestCase
{
    /** Every screen the admin has. */
    private const SCREENS = [
        Routes::RATES,
        Routes::REVIEW,
        Routes::EXPORT_CSV,
        Routes::INBOX,
        Routes::SHIPMENTS,
        Routes::SHIPMENT_NEW,
        Routes::SECURITY,
        '/admin/settings',
        '/admin/content',
        '/admin/rates/export',
    ];

    /** Every route that changes something. */
    private const WRITES = [
        Routes::SAVE,
        Routes::ADD_DESTINATION,
        Routes::REMOVE_DESTINATION,
        Routes::SORT_DESTINATIONS,
        Routes::UNDO_ROW,
        Routes::FILL,
        Routes::BULK,
        Routes::TEST,
        Routes::REVIEW,
        Routes::DISCARD,
        Routes::RESTORE,
        Routes::PUBLISH,
        Routes::HOLD,
        Routes::INBOX_UPDATE,
        Routes::SHIPMENT_CREATE,
        Routes::SHIPMENT_UPDATE,
        Routes::SHIPMENT_EVENT,
        Routes::TOTP_BEGIN,
        Routes::TOTP_CONFIRM,
        Routes::TOTP_DISABLE,
    ];

    public function testNoAdminScreenIsReachableWithoutASession(): void
    {
        foreach (self::SCREENS as $path) {
            $answer = $this->get($path);

            $this->assertSame(302, $answer->response->status, $path . ' answered ' . $answer->response->status);
            $this->assertSame(Routes::LOGIN, $this->location($answer), $path . ' sent the visitor somewhere else');
            $this->assertStringNotContains('<table class="rates', $answer->body(), $path . ' rendered the editor anyway');
        }
    }

    public function testNoAdminWriteIsReachableWithoutASession(): void
    {
        foreach (self::WRITES as $path) {
            // With a token that cannot be valid: the session check has to come
            // first, so this must be a redirect and not a token refusal.
            $answer = $this->post($path, [Csrf::FIELD => 'not-a-token']);

            $this->assertSame(302, $answer->response->status, $path . ' answered ' . $answer->response->status);
            $this->assertSame(Routes::LOGIN, $this->location($answer), $path . ' did not send the visitor to the sign-in');
        }
    }

    public function testASessionWhoseAdminRowIsGoneIsNotAnAdmin(): void
    {
        $this->admin();
        $sid = $this->signIn();

        $this->assertSame(200, $this->adminGet(Routes::RATES)->response->status, 'the editor with a session');

        // Removed from the table, session untouched: the next request is a
        // stranger, and the dead session is deleted rather than left alive.
        Db::exec('DELETE FROM admins');

        $answer = $this->adminGet(Routes::RATES);

        $this->assertSame(302, $answer->response->status, 'a session with no admin row still opened the editor');
        $this->assertFalse($this->sessionExists($sid), 'the orphaned session row was left behind');
    }

    public function testEveryWriteRefusesAPostWithoutAValidToken(): void
    {
        $this->admin();
        $this->signIn();

        foreach (self::WRITES as $path) {
            $answer = $this->post($path, ['_token' => 'wrong'], [], $this->cookies());

            // 403, and the designed error page - never the admin, and never a
            // stack trace. (The page's own wording is `ErrorPage`'s: it gives
            // every status but 404 the same two sentences on purpose.)
            //
            // It was 419 until the go-live rehearsal measured an unregistered
            // status leaving real Apache as a 500; the reasoning is written out
            // in Core\HttpError::invalidToken(). What a machine client needs in
            // order to tell this apart from "you may not publish" - which is
            // also a 403 - is the envelope's `invalid_token`, and
            // testABadTokenStillSaysInvalidTokenToAMachineClient() holds that.
            $this->assertSame(403, $answer->response->status, $path . ' accepted a bad token');
            $this->assertStringNotContains('SQLSTATE', $answer->body(), $path . ' leaked a database error');
            $this->assertStringNotContains('<table class="rates', $answer->body(), $path . ' rendered the editor anyway');
        }
    }

    public function testACrossSiteOriginIsRefusedEvenWithATokenBecauseItCannotHaveOne(): void
    {
        $this->admin();
        $this->signIn();

        $answer = $this->post(
            Routes::SAVE,
            [Csrf::FIELD => $this->token()],
            ['origin' => 'https://attacker.example'],
            $this->cookies(),
        );

        $this->assertSame(403, $answer->response->status, 'a cross-site Origin was accepted');
    }

    /**
     * THE ONE THING 419 WAS DOING THAT 403 DOES NOT DO BY ITSELF.
     *
     * A rejected token and a refused owner-only action are both 403 now, so the
     * status alone no longer tells a machine client which of the two happened -
     * and the two need opposite responses: reload the page and post again, or
     * stop, you are not allowed. The word is therefore carried on the error and
     * printed in the envelope, and this is the test that stops it being dropped
     * the next time somebody tidies `ErrorPage::code()`.
     *
     * Asked for as JSON, because that is the only caller that reads a code: a
     * browser gets the designed page, which says the same thing in English.
     */
    public function testABadTokenStillSaysInvalidTokenToAMachineClient(): void
    {
        $this->admin();
        $this->signIn();

        $answer = $this->post(
            Routes::SAVE,
            [Csrf::FIELD => 'wrong'],
            ['accept' => 'application/json'],
            $this->cookies(),
        );

        $this->assertSame(403, $answer->response->status, 'a bad token');
        $this->assertSame('invalid_token', $answer->json()['error']['code'] ?? '', 'the envelope lost the word');

        // And the other 403 on the same table is still a different word, which
        // is the whole point of keeping one.
        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $refused = $this->post(
            Routes::PUBLISH,
            [Csrf::FIELD => $this->token(), 'expectedBase' => '1'],
            ['accept' => 'application/json'],
            $this->cookies(),
        );

        $this->assertSame(403, $refused->response->status, 'an editor publishing');
        $this->assertSame('forbidden', $refused->json()['error']['code'] ?? '', 'the two 403s became one word');
    }

    public function testAnEditorCannotPublish(): void
    {
        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $before = $this->liveVersion();
        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => (string) $before]);

        $this->assertSame(403, $answer->response->status, 'an editor published');
        $this->assertSame($before, $this->liveVersion(), 'an editor changed the live version');
        $this->assertSame(0, $this->audited('publish'), 'a refused publish was audited as a publish');
    }

    public function testAnEditorCannotResumePricesButCanHoldThem(): void
    {
        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $on = $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Back on Monday.']);
        $this->assertRedirects(Routes::RATES, $on, 'an editor holding prices');
        $this->assertSame(1, $this->audited('hold_on'), 'the hold was not audited');

        $off = $this->adminPost(Routes::HOLD, ['on' => '0']);
        $this->assertSame(403, $off->response->status, 'an editor resumed prices');
        $this->assertSame(0, $this->audited('hold_off'), 'a refused resume was audited');
    }

    public function testAnOwnerMayResumePrices(): void
    {
        $this->admin();
        $this->signIn();

        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Updating.']);
        $off = $this->adminPost(Routes::HOLD, ['on' => '0']);

        $this->assertRedirects(Routes::RATES, $off, 'an owner resuming prices');
        $this->assertSame(1, $this->audited('hold_off'), 'the resume was not audited');
    }

    public function testTheAdminIsNeverIndexable(): void
    {
        $this->admin();
        $this->signIn();

        foreach ([Routes::RATES, Routes::INBOX, Routes::SECURITY, Routes::LOGIN] as $path) {
            $body = $path === Routes::LOGIN ? $this->get($path)->body() : $this->adminGet($path)->body();

            $this->assertStringContains('name="robots" content="noindex, nofollow"', $body, $path . ' is indexable');
        }
    }

    public function testAdminPagesAreNeverCached(): void
    {
        $this->admin();
        $this->signIn();

        $answer = $this->adminGet(Routes::RATES);

        $this->assertStringContains('no-store', $answer->header('Cache-Control'), 'the editor may be cached');
    }

    public function testSigningOutRevokesTheSessionEverywhere(): void
    {
        $this->admin();
        $sid = $this->signIn();

        $answer = $this->adminPost(Routes::LOGOUT);

        $this->assertRedirects(Routes::LOGIN, $answer, 'signing out');
        $this->assertFalse($this->sessionExists($sid), 'the session row survived the sign-out');
        $this->assertSame(1, $this->audited('logout'), 'the sign-out was not audited');

        // The cookie the browser still holds must not work any more.
        $again = $this->get(Routes::RATES, [], [], [Session::COOKIE => $sid]);
        $this->assertSame(302, $again->response->status, 'the old session id still opened the editor');
    }
}
