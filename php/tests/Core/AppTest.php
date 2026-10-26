<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\App;
use Speedat\Core\Config;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Response;
use Speedat\Tests\TestCase;

/**
 * The container, which had no test at all.
 *
 * That is worth saying plainly, because App is where the Content-Security-Policy
 * is decided and where the per-request nonce is minted. Those are the two things
 * standing between an injected `<script>` and a customer's browser, and they
 * were applied by code nobody had ever asserted anything about. A directive
 * dropped in a refactor would have been invisible: the site works perfectly well
 * with no CSP at all.
 *
 * WHAT IS PINNED HERE. That every response gets the headers - including error
 * pages, which is the case a per-handler header always misses. That the nonce is
 * decided ONCE per request and is what the CSP actually names. That HSTS and
 * upgrade-insecure-requests are conditional in the direction that cannot lock
 * anybody out. And that a config value cannot smuggle a directive of its own
 * into the policy.
 *
 * AND WHO GETS FIREBASE (2026-10-26). Only a response the sign-in page marked
 * (Response::allowFirebaseAuth()) is widened, only when all four auth.firebase_*
 * values are set, and then by the complete list from code - including
 * https://apis.google.com, which the documented csp_script value lacked and
 * without which the Google popup never finished.
 */
final class AppTest extends TestCase
{
    /** A complete Firebase web config, as the console hands it out. */
    private const FIREBASE = [
        'auth.firebase_project_id'  => 'speedat-test-1234',
        'auth.firebase_api_key'     => 'AIzaSy-test-not-a-real-key',
        'auth.firebase_auth_domain' => 'speedat-test-1234.firebaseapp.com',
        'auth.firebase_app_id'      => '1:1234567890:web:abcdef123456',
    ];

    /** @var array<string,mixed> */
    private array $savedConfig = [];

    /** @var array<string,mixed> $_SERVER as it was before request() replaced it. */
    private array $savedServer = [];

    public function setUp(): void
    {
        $this->savedConfig = [
            'security.hsts'        => Config::bool('security.hsts', false),
            'security.csp_script'  => Config::str('security.csp_script'),
            'security.csp_connect' => Config::str('security.csp_connect'),
            'security.csp_frame'   => Config::str('security.csp_frame'),
        ];

        foreach (self::FIREBASE as $key => $value) {
            $this->savedConfig[$key] = Config::get($key);
            Config::set($key, '');
        }

        Config::set('security.csp_script', '');
        Config::set('security.csp_connect', '');
        Config::set('security.csp_frame', '');

        $this->savedServer = $_SERVER;
    }

    /**
     * Put BOTH globals back, and the $_SERVER one is not housekeeping.
     *
     * request() replaces $_SERVER wholesale, which is what makes it a real
     * fromGlobals() call. Leaving it replaced changed the behaviour of a LATER
     * suite: BootstrapCliTest copies the environment into a child process, found
     * this test's five fake CGI variables sitting in $_SERVER, and php-cgi
     * refused to start ("Security Alert! The PHP CGI cannot be accessed
     * directly") because that looks like a web request with no REDIRECT_STATUS.
     * It passed alone and failed in the full run - the exact shape of a test that
     * wastes a day. BootstrapCliTest now reads getenv() rather than $_SERVER, and
     * this suite cleans up after itself; either fix alone would do, and a global
     * left modified is worth fixing anyway.
     */
    public function tearDown(): void
    {
        foreach ($this->savedConfig as $key => $value) {
            Config::set($key, $value);
        }

        $_SERVER = $this->savedServer;
    }

    // ------------------------------------------------------------------- nonce

    /**
     * One nonce per request, decided at construction.
     *
     * If it were generated per read, the CSP header would name one value and the
     * `<script nonce>` in the page would carry another, and every inline script
     * on the site would be blocked - which is a blank calculator, not a warning.
     */
    public function testTheNonceIsDecidedOncePerRequest(): void
    {
        $app = new App($this->request());

        $first = $app->nonce;

        $this->assertSame($first, $app->nonce, 'read twice, same value');
        $this->assertTrue(strlen($first) >= 16, 'long enough not to be guessed: ' . strlen($first));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]+$/', $first), 'and safe to put in a header and an attribute');
    }

    public function testTwoRequestsGetDifferentNonces(): void
    {
        // A nonce that repeated across requests would be a nonce an attacker can
        // read off one page and use on the next.
        $this->assertNotSame((new App($this->request()))->nonce, (new App($this->request()))->nonce);
    }

    public function testTheCspNamesThisRequestsNonceAndNoOther(): void
    {
        $app      = new App($this->request());
        $response = Response::html('<h1>hello</h1>');

        $this->send($app, $response);

        $csp = $response->headers()['Content-Security-Policy'] ?? '';

        $this->assertStringContains("'nonce-" . $app->nonce . "'", $csp);
        $this->assertStringContains('script-src', $csp);
    }

    // ----------------------------------------------------------------- headers

    /**
     * Every response, not every handler. The error pages are the ones a
     * per-handler header always forgets, and an error page is exactly where a
     * reflected value is most likely to end up.
     */
    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        $expected = [
            'Content-Security-Policy',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Cross-Origin-Opener-Policy',
            'Permissions-Policy',
        ];

        foreach ([Response::html('page'), Response::text('500', 500), Response::json([], 404), Response::empty()] as $response) {
            $this->send(new App($this->request()), $response);

            foreach ($expected as $header) {
                $this->assertTrue(
                    $response->hasHeader($header),
                    $header . ' is missing from a ' . $response->status . ' response'
                );
            }
        }
    }

    public function testTheHeadersSayTheThingsTheyAreThereToSay(): void
    {
        $response = Response::html('page');
        $this->send(new App($this->request()), $response);

        $headers = $response->headers();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertSame('same-origin-allow-popups', $headers['Cross-Origin-Opener-Policy'], 'plain same-origin breaks the Firebase Google sign-in popup');

        $csp = $headers['Content-Security-Policy'];
        foreach (["default-src 'self'", "base-uri 'none'", "object-src 'none'", "frame-ancestors 'none'", "form-action 'self'"] as $directive) {
            $this->assertStringContains($directive, $csp);
        }

        // The two anti-framing headers must agree. This used to send
        // `X-Frame-Options: SAMEORIGIN` beside `frame-ancestors 'none'`: the
        // weaker of the two was what a browser too old to read a CSP was told,
        // and docs/quality-bar.md §2 asks for DENY. Asserted together, because
        // the bug was never one header being wrong - it was the pair
        // disagreeing on the same response.
        $this->assertSame('DENY', $headers['X-Frame-Options'], 'X-Frame-Options must say what the CSP says');
        $this->assertStringContains("frame-ancestors 'none'", $csp);

        // Deliberate, and the reason is in App: the v3 design uses style
        // attributes, and a nonce on style-src makes 'unsafe-inline' be ignored.
        $this->assertStringContains("style-src 'self' 'unsafe-inline'", $csp);
    }

    /**
     * Only one response gets the headers written onto it, and send() is the place
     * that does it - so a handler cannot opt out by not asking.
     */
    public function testAHandlerCannotOptOutByReturningABareResponse(): void
    {
        $response = Response::empty(204);

        $this->assertSame([], $response->headers(), 'nothing on it before send()');

        $this->send(new App($this->request()), $response);

        $this->assertTrue($response->hasHeader('Content-Security-Policy'));
    }

    // ------------------------------------------------------------- conditionals

    /**
     * HSTS is opt-in AND https-only, in that order.
     *
     * A browser remembers it for a year. Setting it before the PHP site is the
     * live https site locks visitors out of a domain that cannot yet serve them,
     * and there is no way to take it back.
     */
    public function testHstsNeedsBothTheConfigFlagAndHttps(): void
    {
        Config::set('security.hsts', true);

        $overHttp = Response::html('x');
        $this->send(new App($this->request(https: false)), $overHttp);
        $this->assertFalse($overHttp->hasHeader('Strict-Transport-Security'), 'never over plain http, whatever the config says');

        Config::set('security.hsts', false);
        $noFlag = Response::html('x');
        $this->send(new App($this->request(https: true)), $noFlag);
        $this->assertFalse($noFlag->hasHeader('Strict-Transport-Security'), 'and never without being asked');

        Config::set('security.hsts', true);
        $both = Response::html('x');
        $this->send(new App($this->request(https: true)), $both);
        $this->assertStringContains('max-age=31536000', $both->headers()['Strict-Transport-Security'] ?? '');
    }

    public function testUpgradeInsecureRequestsOnlyAppearsOnAnHttpsRequest(): void
    {
        $overHttps = Response::html('x');
        $this->send(new App($this->request(https: true)), $overHttps);
        $this->assertStringContains('upgrade-insecure-requests', $overHttps->headers()['Content-Security-Policy']);

        // Over plain http on the dev server it would upgrade every asset request
        // to a scheme the dev server does not speak.
        $overHttp = Response::html('x');
        $this->send(new App($this->request(https: false)), $overHttp);
        $this->assertStringNotContains('upgrade-insecure-requests', $overHttp->headers()['Content-Security-Policy']);
    }

    // ----------------------------------------------------------- config sources

    public function testAnExtraSourceFromConfigIsAddedToTheRightDirective(): void
    {
        Config::set('security.csp_script', 'https://www.gstatic.com');
        Config::set('security.csp_connect', 'https://identitytoolkit.googleapis.com');

        $response = Response::html('x')->allowFirebaseAuth();
        $this->send(new App($this->request()), $response);

        $csp = $response->headers()['Content-Security-Policy'];

        $this->assertStringContains("script-src 'self' 'nonce-", $csp);
        $this->assertStringContains('https://www.gstatic.com', $csp);
        $this->assertStringContains("connect-src 'self' https://identitytoolkit.googleapis.com", $csp);
    }

    /**
     * A config value may name hosts and nothing else.
     *
     * A semicolon would start a directive of its own, and `'unsafe-inline'` in
     * script-src would switch the nonce off entirely - which is the one thing the
     * whole policy is built to prevent. Both are refused, and the refusal drops
     * the value rather than trying to clean it.
     */
    public function testAConfigValueCannotSmuggleADirectiveIntoThePolicy(): void
    {
        foreach (["'unsafe-inline'", "; script-src *", "https://a.test; object-src *", "'unsafe-eval'"] as $hostile) {
            Config::set('security.csp_script', $hostile);

            // Marked: the one response the extras are allowed to reach.
            $response = Response::html('x')->allowFirebaseAuth();
            $this->send(new App($this->request()), $response);

            $csp = $response->headers()['Content-Security-Policy'];

            $this->assertStringNotContains('unsafe-inline', explode('style-src', $csp)[0], 'refused: ' . $hostile);
            $this->assertStringNotContains('unsafe-eval', $csp, 'refused: ' . $hostile);
            $this->assertStringNotContains('object-src *', $csp, 'refused: ' . $hostile);
            $this->assertStringContains("script-src 'self' 'nonce-", $csp, 'and the directive is still intact');
        }
    }

    // ------------------------------------------------------- Firebase sign-in

    public function testTheSignInPageGetsTheCompleteFirebaseListFromCode(): void
    {
        $this->configureFirebase();

        $response = Response::html('x')->allowFirebaseAuth();
        $this->send(new App($this->request()), $response);
        $csp = $response->headers()['Content-Security-Policy'];

        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-[^']+' https:\\/\\/www\\.gstatic\\.com https:\\/\\/apis\\.google\\.com;/", $csp), $csp);
        $this->assertStringContains(
            "connect-src 'self' https://identitytoolkit.googleapis.com https://securetoken.googleapis.com https://www.googleapis.com;",
            $csp,
        );
        $this->assertStringContains("frame-src 'self' https://speedat-test-1234.firebaseapp.com https://accounts.google.com", $csp);
    }

    public function testEveryOtherResponseKeepsThePlainPolicy(): void
    {
        $this->configureFirebase();
        Config::set('security.csp_script', 'https://extra.example');

        $response = Response::html('x');
        $this->send(new App($this->request()), $response);
        $csp = $response->headers()['Content-Security-Policy'];

        foreach (['gstatic', 'apis.google.com', 'firebaseapp', 'googleapis', 'accounts.google.com', 'extra.example'] as $host) {
            $this->assertStringNotContains($host, $csp, 'an unmarked response trusts ' . $host);
        }
        $this->assertStringContains("connect-src 'self';", $csp);
        $this->assertStringContains("frame-src 'self'", $csp);
    }

    public function testAMarkedResponseWithFirebaseUnconfiguredIsPlainToo(): void
    {
        // Three of four is not configured: the button does not render either.
        $this->configureFirebase();
        Config::set('auth.firebase_app_id', '');

        $response = Response::html('x')->allowFirebaseAuth();
        $this->send(new App($this->request()), $response);
        $csp = $response->headers()['Content-Security-Policy'];

        $this->assertStringNotContains('gstatic', $csp);
        $this->assertStringContains("connect-src 'self';", $csp);
        $this->assertSame(1, preg_match("/frame-src 'self'(;|$)/", $csp), $csp);
    }

    /**
     * The setup guide is what the owner follows, and its old csp_script line
     * was the bug. Whatever it now says about CSP, it must name the source the
     * code emits and the old docs missed.
     */
    public function testTheSetupGuideNamesApisGoogleCom(): void
    {
        $doc = Paths::root() . '/docs/firebase-setup.md';

        if (!is_file($doc)) {
            $this->skip('docs/firebase-setup.md is not in this tree');
        }

        $this->assertStringContains('https://apis.google.com', (string) file_get_contents($doc));
    }

    // -------------------------------------------------------------- lazy things

    public function testTheContainerHandsBackTheSameObjectEveryTime(): void
    {
        $app = new App($this->request());

        // Built on first use and kept: two Csrf objects would mean a token
        // signed against one session secret and checked against another.
        $this->assertSame($app->session(), $app->session());
        $this->assertSame($app->csrf(), $app->csrf());
        $this->assertSame($app->view(), $app->view());
    }

    public function testPageRendersHtmlThatIsNeverCached(): void
    {
        $app = new App($this->request());

        $response = $app->page('errors/error', ['status' => 404, 'message' => 'Nothing here', 'title' => 'Page not found']);

        $this->assertSame(200, $response->status, 'the status is the caller\'s business, not the template\'s');
        $this->assertStringContains('text/html', $response->headers()['Content-Type']);
        $this->assertStringContains('no-store', $response->headers()['Cache-Control'], 'a personalised page must never be cached');

        $this->assertSame(404, $app->page('errors/error', ['status' => 404, 'message' => 'x', 'title' => 'y'], 404)->status);
    }

    /** HEAD gets every header the GET would, and no body. */
    public function testAHeadRequestSendsTheHeadersAndNoBody(): void
    {
        $app      = new App($this->request(method: 'HEAD'));
        $response = Response::html('<h1>a body</h1>');

        $printed = $this->send($app, $response);

        $this->assertSame('', $printed, 'no body went out');
        $this->assertTrue($response->hasHeader('Content-Security-Policy'), 'but the headers were still decided');
    }

    // ---------------------------------------------------------------- helpers

    private function configureFirebase(): void
    {
        foreach (self::FIREBASE as $key => $value) {
            Config::set($key, $value);
        }
    }

    /**
     * App::send() echoes the body, which under the test runner lands in the
     * middle of the runner's own output and makes the report unreadable. The
     * headers are what is being asserted, so the body is swallowed - and the
     * buffer is closed even when an assertion throws, or every test after this
     * one would print into a buffer nobody reads.
     */
    private function send(App $app, Response $response): string
    {
        ob_start();

        try {
            $app->send($response);
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }

    private function request(string $method = 'GET', bool $https = false): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => '/',
            'SCRIPT_NAME'    => '/index.php',
            'REMOTE_ADDR'    => '127.0.0.1',
            'HTTP_HOST'      => 'speedat.test',
        ] + ($https ? ['HTTPS' => 'on'] : []);

        return Request::fromGlobals();
    }
}
