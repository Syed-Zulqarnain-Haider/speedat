<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Session;
use Speedat\Http\Admin\PendingLogin;
use Speedat\View\Admin\Routes;

/**
 * The second factor's guess limit belongs to the ACCOUNT.
 *
 * It used to be keyed on the pending login, and a fresh pending login is minted
 * every time the first factor succeeds - so anybody holding the password could
 * redo it every six codes and keep guessing, about sixty codes per fifteen
 * minutes with no lockout (review, 2026-10-23). What is checked here is that
 * redoing the password no longer buys more guesses, and that the page offers no
 * password form a deployment has switched off.
 */
final class B7LoginCodeLimitTest extends AdminTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** The controller's CODE_ATTEMPTS. */
    private const CODE_ATTEMPTS = 6;

    public function testRedoingThePasswordDoesNotResetTheCodeLimit(): void
    {
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $limited = false;

        // Two codes per pending login, a fresh pending login each time: the old
        // per-record bucket never saw more than two and never said no.
        for ($round = 0; $round < self::CODE_ATTEMPTS; $round++) {
            $pending = $this->pendingCookie();

            for ($i = 0; $i < 2; $i++) {
                $answer = $this->postWithToken(Routes::LOGIN_CODE, ['code' => '000000'], $pending);

                if ($answer->response->status === 429) {
                    $limited = true;
                }
            }
        }

        $this->assertTrue($limited, (self::CODE_ATTEMPTS * 2) . ' wrong codes across fresh pending logins drew no rate limit');

        // And a brand-new pending login with the RIGHT code is still refused
        // while the window is open: the limit is the point, not a hint.
        $pending = $this->pendingCookie();
        $right   = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $this->totp()->codeAt(self::SECRET)], $pending);

        $this->assertSame(429, $right->response->status, 'a fresh first factor bought another round of codes');
    }

    public function testAGoogleOnlyDeploymentRendersNoPasswordForm(): void
    {
        $before = Config::bool('auth.allow_password_login', false);
        Config::set('auth.allow_password_login', false);
        Config::set('auth.firebase_project_id', 'speedat-test-1234');
        $this->admin();

        try {
            $body = $this->get(Routes::LOGIN)->body();

            $this->assertStringContains('action="' . Routes::LOGIN_GOOGLE . '"', $body, 'no Google form');
            $this->assertStringNotContains('action="' . Routes::LOGIN_PASSWORD . '"', $body, 'a password form that can only refuse');
            $this->assertStringNotContains('type="password"', $body, 'a password box that can only refuse');
            $this->assertStringNotContains('or with email and password', $body, 'an "or" pointing at nothing');
            $this->assertStringContains('data-google-fallback', $body, 'nothing to read if Google never loads');
        } finally {
            Config::set('auth.firebase_project_id', '');
            Config::set('auth.allow_password_login', $before);
        }
    }

    /* ------------------------------------------------------------ helpers */

    /** As AdminLoginTest: read the page, take its token and cookie, then post. */
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

    /** @return array<string,string> */
    private function pendingCookie(): array
    {
        $answer  = $this->postWithToken(Routes::LOGIN_PASSWORD, ['email' => self::OWNER, 'password' => self::PASSWORD], []);
        $pending = $answer->cookie(PendingLogin::COOKIE);

        $this->assertTrue(is_string($pending) && $pending !== '', 'the first factor did not start a pending login');

        return [PendingLogin::COOKIE => (string) $pending];
    }
}
