<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\View\Admin\LoginPage;
use Speedat\View\Admin\Routes;

/**
 * The sign-in on a deployment with `auth.allow_password_login` off - the sample
 * config's default. It used to render the password form anyway, which refused
 * every submit and was the only thing on screen whenever Google's script did not
 * load (review, 2026-10-23).
 */
final class B7LoginGoogleOnlyTest extends AdminViewTestCase
{
    public function testAGoogleOnlyPageHasNoPasswordFormAndSaysWhatToDoWithoutTheButton(): void
    {
        $page = new LoginPage(googleReady: true, passwordsEnabled: false);
        $html = $this->view()->render('admin/login', ['page' => $page]);

        $this->assertTrue($page->googleOnly(), 'Google is the only way in');
        $this->assertStringContains('action="' . Routes::LOGIN_GOOGLE . '"', $html);
        $this->assertStringNotContains('action="' . Routes::LOGIN_PASSWORD . '"', $html);
        $this->assertStringNotContains('type="password"', $html);
        $this->assertStringNotContains('or with email and password', $html);
        $this->assertStringContains('data-google-fallback', $html, 'an empty card when Google never loads');
        $this->assertStringNotContains('—', $html, 'customer-facing text uses " - " (owner, 2026-10-11)');
    }

    public function testWithPasswordsOnTheFallbackSentenceIsNotPrinted(): void
    {
        $html = $this->view()->render('admin/login', ['page' => new LoginPage(googleReady: true, passwordsEnabled: true)]);

        $this->assertStringContains('action="' . Routes::LOGIN_PASSWORD . '"', $html);
        $this->assertStringContains('data-password-form', $html, 'the hook firebase-signin.js asks before pointing at the form');
        $this->assertStringNotContains('data-google-fallback', $html);
    }
}
