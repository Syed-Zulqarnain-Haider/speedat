<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Core\Config;
use Speedat\Http\Admin\LoginController;
use Speedat\Tests\TestCase;

/**
 * LoginController::firebaseConfigJson() - the JSON the sign-in page prints raw
 * into a <script type="application/json"> block. Its docblock says the
 * visibility rule "is the thing worth a test, and it lives here", and there
 * was no such test until 2026-10-26.
 *
 * Raw printing is why the escaping matters: the view does not escape this
 * value (app/Views/admin/login.php says so), so the JSON flags are the only
 * thing between a config value and a closed </script>.
 */
final class B7FirebaseConfigJsonTest extends TestCase
{
    private const KEYS = [
        'auth.firebase_api_key',
        'auth.firebase_auth_domain',
        'auth.firebase_project_id',
        'auth.firebase_app_id',
        'auth.firebase_sdk_version',
    ];

    /** @var array<string,mixed> */
    private array $saved = [];

    public function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = Config::get($key);
        }

        $this->complete();
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            Config::set($key, $value);
        }
    }

    public function testAnyOneMissingValueMeansNoConfigAtAll(): void
    {
        foreach (array_slice(self::KEYS, 0, 4) as $key) {
            foreach (['', '   '] as $blank) {
                $this->complete();
                Config::set($key, $blank);

                $this->assertSame('', LoginController::firebaseConfigJson(), $key . ' = ' . var_export($blank, true));
            }
        }
    }

    public function testAllFourGiveExactlyTheFiveKeysTheBrowserReads(): void
    {
        $decoded = json_decode(LoginController::firebaseConfigJson(), true);

        $this->assertSame([
            'apiKey'     => 'AIzaSy-test-not-a-real-key',
            'authDomain' => 'speedat-test-1234.firebaseapp.com',
            'projectId'  => 'speedat-test-1234',
            'appId'      => '1:1234567890:web:abcdef123456',
            'sdkVersion' => '10.12.2',
        ], $decoded);
    }

    public function testOnlyAThreePartVersionIsBelieved(): void
    {
        foreach (['11', 'latest', '10.12.2"><script>', '../x', ''] as $bad) {
            Config::set('auth.firebase_sdk_version', $bad);

            $this->assertSame('10.12.2', $this->decoded()['sdkVersion'] ?? null, var_export($bad, true));
        }

        Config::set('auth.firebase_sdk_version', '11.0.1');
        $this->assertSame('11.0.1', $this->decoded()['sdkVersion'] ?? null, 'a real version is kept');
    }

    public function testAHostileValueCannotCloseTheScriptBlock(): void
    {
        $hostile = '</script><script>alert(1)</script>';
        Config::set('auth.firebase_api_key', $hostile);

        $json = LoginController::firebaseConfigJson();

        $this->assertStringNotContains('<', $json);
        $this->assertStringNotContains('>', $json);
        $this->assertSame($hostile, $this->decoded()['apiKey'] ?? null, 'and the browser still reads the value it was given');
    }

    /**
     * The auth domain is also a frame-src source in the page's CSP
     * (Domain\Auth\FirebaseBrowser), so it has to be a hostname. The two ways
     * the console's URL gets pasted are tidied; anything else is "missing".
     */
    public function testTheAuthDomainIsAHostnameOrNothing(): void
    {
        foreach (['https://speedat-test-1234.firebaseapp.com/', 'SPEEDAT-TEST-1234.firebaseapp.com'] as $tidy) {
            Config::set('auth.firebase_auth_domain', $tidy);
            $this->assertSame('speedat-test-1234.firebaseapp.com', $this->decoded()['authDomain'] ?? null, $tidy);
        }

        Config::set('auth.firebase_auth_domain', 'auth.speedat.com.pk');
        $this->assertSame('auth.speedat.com.pk', $this->decoded()['authDomain'] ?? null, 'a custom auth domain');

        foreach (["x.firebaseapp.com 'unsafe-inline'", 'x.firebaseapp.com;script-src *', 'http://x.firebaseapp.com', 'x.firebaseapp.com/__/auth', 'localhost', '10.0.0.1', 'x.firebaseapp.com:443'] as $bad) {
            Config::set('auth.firebase_auth_domain', $bad);
            $this->assertSame('', LoginController::firebaseConfigJson(), var_export($bad, true));
        }
    }

    private function complete(): void
    {
        Config::set('auth.firebase_api_key', 'AIzaSy-test-not-a-real-key');
        Config::set('auth.firebase_auth_domain', 'speedat-test-1234.firebaseapp.com');
        Config::set('auth.firebase_project_id', 'speedat-test-1234');
        Config::set('auth.firebase_app_id', '1:1234567890:web:abcdef123456');
        Config::set('auth.firebase_sdk_version', '');
    }

    /** @return array<string,mixed> */
    private function decoded(): array
    {
        $decoded = json_decode(LoginController::firebaseConfigJson(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
