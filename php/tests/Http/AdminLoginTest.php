<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\Session;
use Speedat\Http\Admin\PendingLogin;
use Speedat\View\Admin\Routes;

/**
 * Signing in: both methods, the second factor, and everything the sign-in
 * refuses.
 *
 * The four statements worth the most here:
 *
 *   NO ENUMERATION. An address that is not an admin, an admin with no password,
 *   and a wrong password produce the SAME page with the SAME sentence. A
 *   difference of one word turns this form into a tool for harvesting the admin
 *   list.
 *
 *   THE CODE IS REQUIRED AND SINGLE-USE. A correct password alone must not
 *   produce a session when 2FA is on, a wrong code must not, and a code that has
 *   been spent must not work a second time - which is the difference between a
 *   second factor and a formality.
 *
 *   THE PENDING STATE IS NOT A SESSION. Between the two steps there is no
 *   session row and no admin page opens, so no route can mistake half a sign-in
 *   for a signed-in admin.
 *
 *   A FORGED GOOGLE TOKEN IS REFUSED AND SETS NO COOKIE. That is the one thing
 *   the browser harness can check about the Google path without a real consent
 *   screen, and it is checked here too, where it costs nothing to run.
 */
final class AdminLoginTest extends AdminTestCase
{
    /** A 20-byte base32 secret, the size an authenticator app expects. */
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /* ----------------------------------------------------------- the screen */

    public function testTheSignInPageOffersBothMethodsWhenBothAreConfigured(): void
    {
        Config::set('auth.firebase_project_id', 'speedat-test-1234');
        $this->admin();

        $body = $this->get(Routes::LOGIN)->body();

        $this->assertStringContains('action="' . Routes::LOGIN_GOOGLE . '"', $body, 'no Google form');
        $this->assertStringContains('action="' . Routes::LOGIN_PASSWORD . '"', $body, 'no password form');
        $this->assertStringContains('type="password"', $body, 'no password box');
        $this->assertStringContains('name="' . Csrf::FIELD . '"', $body, 'no CSRF token');
    }

    public function testTheSignInPageSaysSoWhenNobodyCanSignIn(): void
    {
        $this->assertStringContains(
            'Nobody has been added as an admin yet',
            $this->get(Routes::LOGIN)->body(),
            'an empty admins table is not called out',
        );
    }

    public function testAnAlreadySignedInAdminIsSentToTheEditor(): void
    {
        $this->admin();
        $this->signIn();

        $answer = $this->get(Routes::LOGIN, [], [], $this->cookies());

        $this->assertSame(302, $answer->response->status, 'the sign-in page was rendered under a live session');
        $this->assertSame(Routes::RATES, $this->location($answer), 'sent somewhere other than the editor');
    }

    /* -------------------------------------------------------- what it refuses */

    public function testAnUnknownAddressAndAWrongPasswordReadIdentically(): void
    {
        $this->admin();

        $unknown = $this->attempt('nobody@example.invalid', 'whatever-long-enough');
        $wrong   = $this->attempt(self::OWNER, 'not-the-password');

        $this->assertSame(401, $unknown->response->status, 'an unknown address');
        $this->assertSame(401, $wrong->response->status, 'a wrong password');
        $this->assertSame(
            $this->notice($unknown),
            $this->notice($wrong),
            'the two failures read differently, which tells a stranger who is an admin',
        );
        $this->assertSame('Email or password is wrong.', $this->notice($wrong), 'the refusal');
        $this->assertFalse($this->anySession(), 'a refused sign-in left a session behind');
    }

    public function testAnAdminWithNoPasswordReadsTheSameWay(): void
    {
        $this->admin(self::OWNER, 'owner', null);

        $answer = $this->attempt(self::OWNER, 'anything-at-all-here');

        $this->assertSame(401, $answer->response->status, 'status');
        $this->assertSame('Email or password is wrong.', $this->notice($answer), 'the refusal');
    }

    public function testRepeatedWrongPasswordsAreRateLimitedPerAccount(): void
    {
        $this->admin();

        $limit   = max(3, Config::int('limits.login_per_15min', 10));
        $refused = 0;

        for ($i = 0; $i < $limit + 2; $i++) {
            $answer = $this->attempt(self::OWNER, 'wrong-' . $i);

            if ($answer->response->status === 429) {
                $refused++;
            }
        }

        $this->assertTrue($refused > 0, $limit + 2 . ' wrong passwords in a row drew no rate limit');

        // And the right password is refused too while the window is open: the
        // limit is the point, not a hint.
        $this->assertSame(429, $this->attempt(self::OWNER, self::PASSWORD)->response->status, 'the limiter let the next attempt through');
    }

    /* ---------------------------------------------------------- what it allows */

    public function testAPasswordAloneSignsInAnAccountWithNoSecondFactor(): void
    {
        $this->admin();

        $answer = $this->attempt(self::OWNER, self::PASSWORD);

        $this->assertRedirects(Routes::RATES, $answer, 'a correct password');
        $this->assertTrue($this->anySession(), 'no session was started');
        $this->assertSame(1, $this->audited('login'), 'the sign-in was not audited');
        $this->assertSame(false, $this->auditDetail('login')['secondFactor'] ?? null, 'the audit line claims a second factor');
    }

    public function testAPasswordIsNotEnoughWhenTwoFactorIsOn(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $answer = $this->attempt(self::OWNER, self::PASSWORD);

        $this->assertRedirects(Routes::LOGIN, $answer, 'a correct password with 2FA on');
        $this->assertFalse($this->anySession(), 'the correct password alone produced a session');
        $this->assertNotNull($answer->cookie(PendingLogin::COOKIE), 'no pending login was started');
        $this->assertSame(1, $this->audited('login_first_factor'), 'the first factor was not audited');
        $this->assertSame(0, $this->audited('login'), 'a half-finished sign-in was audited as a sign-in');
    }

    public function testTheCodeStepIsRenderedForAPendingLoginAndNothingElseIs(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $pending = $this->pendingCookie();
        $body    = $this->get(Routes::LOGIN, [], [], $pending)->body();

        $this->assertStringContains('action="' . Routes::LOGIN_CODE . '"', $body, 'no code form');
        $this->assertStringContains('6-digit code', $body, 'the code prompt');
        $this->assertStringContains('action="' . Routes::LOGOUT . '"', $body, 'no way to start again');
        $this->assertStringNotContains('type="password"', $body, 'the password form is still on the code step');

        // And the admin itself is still shut.
        $this->assertSame(302, $this->get(Routes::RATES, [], [], $pending)->response->status, 'a pending login opened the editor');
    }

    public function testAWrongCodeIsRefusedAndTheRightOneSignsIn(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $pending = $this->pendingCookie();

        $wrong = $this->postWithToken(Routes::LOGIN_CODE, ['code' => '000000'], $pending);

        $this->assertRedirects(Routes::LOGIN, $wrong, 'a wrong code');
        $this->assertStringContains('err=code', $this->location($wrong), 'the wrong code did not say so');
        $this->assertFalse($this->anySession(), 'the code "000000" was accepted');
        $this->assertSame(1, $this->audited('login_code_refused'), 'the refused code was not audited');

        $code  = $this->totp()->codeAt(self::SECRET);
        $right = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $code], $pending);

        $this->assertRedirects(Routes::RATES, $right, 'the right code');
        $this->assertTrue($this->anySession(), 'the right code did not produce a session');
        $this->assertSame(1, $this->audited('login'), 'the sign-in was not audited');
        $this->assertSame(true, $this->auditDetail('login')['secondFactor'] ?? null, 'the audit line forgot the second factor');
    }

    public function testASpentCodeCannotBeUsedTwice(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $code = $this->totp()->codeAt(self::SECRET);

        $first = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $code], $this->pendingCookie());
        $this->assertRedirects(Routes::RATES, $first, 'the first use of the code');

        Db::exec('DELETE FROM sessions');

        // A brand new pending login, the same six digits.
        $second = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $code], $this->pendingCookie());

        $this->assertStringContains('err=code', $this->location($second), 'a spent code was accepted');
        $this->assertFalse($this->anySession(), 'the same code worked a second time - there is no replay guard');
    }

    public function testACodeWithNoPendingLoginIsSentBackToTheStart(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $answer = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $this->totp()->codeAt(self::SECRET)], []);

        $this->assertStringContains('err=expired', $this->location($answer), 'a code with no pending login');
        $this->assertFalse($this->anySession(), 'a code with no pending login signed somebody in');
    }

    public function testStartAgainDestroysThePendingLogin(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $pending = $this->pendingCookie();

        $this->assertTrue($this->pendingRows() > 0, 'no pending login was stored');

        $answer = $this->postWithToken(Routes::LOGOUT, [], $pending);

        $this->assertRedirects(Routes::LOGIN, $answer, '"Start again"');
        $this->assertSame(0, $this->pendingRows(), 'the pending login outlived "Start again"');
    }

    public function testTheCodeStepIsRateLimited(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $pending = $this->pendingCookie();
        $refused = 0;

        for ($i = 0; $i < 9; $i++) {
            if ($this->postWithToken(Routes::LOGIN_CODE, ['code' => '000000'], $pending)->response->status === 429) {
                $refused++;
            }
        }

        $this->assertTrue($refused > 0, 'nine wrong codes in a row drew no limit; six digits are guessable');
    }

    /* ------------------------------------------------------------- Google */

    public function testAForgedGoogleTokenIsRefusedAndSetsNoCookie(): void
    {
        // The planted certificate cache is what keeps this off the network:
        // with none, the verifier's first act was a live HTTPS call to Google
        // (2026-10-26). Config is put back by AdminTestCase::tearDown(), so a
        // failing assertion no longer leaves Google sign-in on for the next test.
        $this->googleSignInReady();
        $this->admin();

        // Three base64url segments that parse as a JWT and are signed by nobody.
        $segment = static fn (array $o): string => rtrim(strtr(base64_encode((string) json_encode($o)), '+/', '-_'), '=');
        $forged  = $segment(['alg' => 'RS256', 'kid' => 'not-a-google-key'])
            . '.' . $segment(['iss' => 'https://accounts.google.com', 'email' => self::OWNER, 'exp' => time() + 3600])
            . '.bm90LWEtc2lnbmF0dXJl';

        $answer = $this->postWithToken(Routes::LOGIN_GOOGLE, ['idToken' => $forged], []);

        $this->assertSame(401, $answer->response->status, 'a token signed by nobody was not refused');
        $this->assertSame('That Google sign-in was refused. Try again, or use your email and password.', $this->notice($answer));
        $this->assertFalse($this->anySession(), 'a forged token produced a session');
        $this->assertNull($answer->cookie(Session::COOKIE), 'a forged token set a session cookie');
    }

    public function testTheGoogleButtonSaysWhatItNeedsWhenNoTokenIsPosted(): void
    {
        Config::set('auth.firebase_project_id', 'speedat-test-1234');
        $this->admin();

        $answer = $this->postWithToken(Routes::LOGIN_GOOGLE, [], []);

        $this->assertSame(401, $answer->response->status, 'status');
        $this->assertStringContains('needs JavaScript', $this->notice($answer), 'the no-JavaScript answer');
        $this->assertFalse($this->anySession(), 'a tokenless Google post signed somebody in');
    }

    public function testGoogleSignInSaysSoWhenItIsNotConfigured(): void
    {
        $this->admin();

        $answer = $this->postWithToken(Routes::LOGIN_GOOGLE, ['idToken' => 'anything'], []);

        $this->assertSame(401, $answer->response->status, 'status');
        $this->assertStringContains('not configured', $this->notice($answer), 'the unconfigured answer');
    }

    /* ------------------------------------------- passwords switched off */

    /**
     * auth.allow_password_login = false is the owner's target configuration
     * (Google only), and nothing tested that the switch actually shuts the
     * password route rather than just hiding the form (2026-10-26). The
     * CORRECT password is the case that matters: a wrong one is refused
     * anyway.
     */
    public function testWithPasswordsOffEvenTheRightPasswordGetsNothing(): void
    {
        foreach (['no second factor' => '', 'with a second factor' => self::SECRET] as $why => $secret) {
            $this->empty();
            $this->admin(self::OWNER, 'owner', self::PASSWORD, $secret);
            Config::set('auth.allow_password_login', false);
            // The deployment this switch exists for: Google on. It is also what
            // puts a form - and so a CSRF token - on the page to borrow; a
            // stale tab or a script posting to the old route is the attack.
            Config::set('auth.firebase_project_id', self::FIREBASE_PROJECT);

            $answer = $this->attempt(self::OWNER, self::PASSWORD);

            $this->assertSame(401, $answer->response->status, $why . ': status');
            $this->assertStringContains('switched off', $this->notice($answer), $why . ': the refusal says why');
            $this->assertFalse($this->anySession(), $why . ': a session was started');
            $this->assertNull($answer->cookie(PendingLogin::COOKIE), $why . ': a pending login was started');
            $this->assertSame(0, $this->pendingRows(), $why . ': a pending login was stored');
            $this->assertSame(0, $this->audited('login_first_factor'), $why . ': the password was counted as a first factor');
            $this->assertSame(0, $this->audited('login'), $why . ': audited as a sign-in');
        }
    }

    public function testWithPasswordsOffAGoogleRefusalDoesNotMentionPasswords(): void
    {
        $this->googleSignInReady();
        $this->admin();
        Config::set('auth.allow_password_login', false);

        $answer = $this->postWithToken(Routes::LOGIN_GOOGLE, ['idToken' => $this->googleToken('stranger@example.com')], []);

        $this->assertSame(401, $answer->response->status, 'status');
        $this->assertSame('That Google sign-in was refused. Try again.', $this->notice($answer));
        $this->assertStringNotContains('email and password', $answer->body(), 'a Google-only deployment pointing at a form it does not have');
    }

    /* ------------------------------------------------------------ internals */

    /** One password attempt, with the token and the anonymous CSRF cookie. */
    private function attempt(string $email, string $password): Answer
    {
        return $this->postWithToken(Routes::LOGIN_PASSWORD, ['email' => $email, 'password' => $password], []);
    }

    /**
     * Post to a login route the way a browser does: read the page, take its
     * token and the cookie the token is bound to, then submit.
     *
     * @param array<string,mixed>  $post
     * @param array<string,string> $cookies extra cookies (the pending login)
     */
    private function postWithToken(string $path, array $post, array $cookies): Answer
    {
        $page   = $this->get(Routes::LOGIN, [], [], $cookies);
        $secret = $page->cookie(Session::ANON_COOKIE);

        if (is_string($secret)) {
            $cookies[Session::ANON_COOKIE] = $secret;
        }

        preg_match('/name="' . Csrf::FIELD . '" value="([^"]*)"/', $page->body(), $m);

        return $this->post($path, $post + [Csrf::FIELD => $m[1] ?? ''], [], $cookies);
    }

    /** Sign in with the password and come back with the pending-login cookie. @return array<string,string> */
    private function pendingCookie(): array
    {
        $answer  = $this->attempt(self::OWNER, self::PASSWORD);
        $pending = $answer->cookie(PendingLogin::COOKIE);

        $this->assertTrue(is_string($pending) && $pending !== '', 'the first factor did not start a pending login');

        return [PendingLogin::COOKIE => (string) $pending];
    }

    /** The refusal sentence the page printed. */
    private function notice(Answer $answer): string
    {
        if (preg_match('#role="alert">(.*?)</div>#s', $answer->body(), $m) !== 1) {
            return '';
        }

        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function anySession(): bool
    {
        return Db::value('SELECT 1 FROM sessions LIMIT 1') !== null;
    }

    private function pendingRows(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM app_settings WHERE k LIKE ?', ['plogin:%'], 0);
    }
}
