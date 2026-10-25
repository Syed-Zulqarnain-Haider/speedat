<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\View\Admin\LoginPage;

/**
 * A Google-only deployment with the project id set but one of the three other
 * browser values missing. googleReady is true, the script is never emitted, and
 * the fallback sentence used to tell the owner to turn JavaScript on and reload -
 * which cannot fix a config gap (review, 2026-10-25).
 */
final class B7LoginFirebaseIncompleteTest extends AdminViewTestCase
{
    private const CONFIG = '{"apiKey":"k","authDomain":"d","projectId":"p","appId":"a","sdkVersion":"10.12.2"}';

    public function testWithoutTheBrowserConfigTheFallbackNamesTheConfigNotTheBrowser(): void
    {
        $html = $this->view()->render('admin/login', [
            'page'     => new LoginPage(googleReady: true, passwordsEnabled: false),
            'firebase' => '',
        ]);

        $this->assertStringContains('data-google-fallback', $html, 'an empty card');
        $this->assertStringContains('config/config.php', $html, 'the file to edit');
        $this->assertStringContains('auth.firebase_api_key', $html, 'the keys to set');
        $this->assertStringNotContains('turn JavaScript on', $html, 'advice that reloading cannot make true');
        $this->assertStringNotContains('id="firebase-config"', $html);
        $this->assertStringNotContains('—', $html, 'customer-facing text uses " - " (owner, 2026-10-11)');
    }

    public function testWithTheBrowserConfigTheFallbackIsTheBrowserSentence(): void
    {
        $html = $this->view()->render('admin/login', [
            'page'     => new LoginPage(googleReady: true, passwordsEnabled: false),
            'firebase' => self::CONFIG,
        ]);

        $this->assertStringContains('turn JavaScript on', $html);
        $this->assertStringNotContains('auth.firebase_api_key', $html);
        $this->assertStringContains('id="firebase-config"', $html);
    }
}
