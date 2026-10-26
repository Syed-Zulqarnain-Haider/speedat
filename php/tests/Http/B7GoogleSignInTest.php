<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Session;
use Speedat\Http\Admin\PendingLogin;
use Speedat\View\Admin\Routes;

/**
 * Google sign-in through the real controller with a VALID token - which no test
 * did until 2026-10-26. AdminLoginTest only ever posted a forged token, so the
 * path the owner actually uses (verified token, admins lookup, pending login,
 * second factor, session) had never run under the suite, and neither had any of
 * the refusals a validly signed token can still earn.
 *
 * No network anywhere: AdminTestCase::googleSignInReady() plants the cache
 * GoogleCertificates would have written, holding this run's own key pair, and
 * googleToken() signs with it.
 *
 * WHAT IS PINNED, in the order a token meets the checks:
 *
 *   503, NOT 401, WHEN WE COULD NOT CHECK. Certificates unobtainable (Google
 *   down past the grace window, outbound HTTPS blocked, a cache directory in
 *   the web root) is the server's problem and is worded as one.
 *
 *   GOOGLE ONLY. A token Firebase minted for Email/Password or an email link
 *   is refused, however verified its address.
 *
 *   FRESH ONLY. A sign-in more than five minutes old is refused: an ID token
 *   lives an hour, and one lifted from a browser should not be good for it.
 *
 *   ITS OWN RATE-LIMIT BUCKET. Wrong passwords typed at the owner's address
 *   used to lock the owner out of Google too.
 *
 * And the Content-Security-Policy, which only the sign-in page widens.
 */
final class B7GoogleSignInTest extends AdminTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const REFUSED = 'That Google sign-in was refused. Try again, or use your email and password.';

    private int $logOffset = 0;

    private bool $logReadable = false;

    /** @var mixed */
    private $docRoot = null;

    /** @var array<string,string> */
    private array $savedCsp = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

        foreach (['security.csp_script', 'security.csp_connect', 'security.csp_frame'] as $key) {
            $this->savedCsp[$key] = Config::str($key);
            Config::set($key, '');
        }

        $this->logReadable = Paths::ensureWritable(Paths::logs());
        $this->logOffset   = $this->logReadable ? $this->logLength() : 0;
    }

    public function tearDown(): void
    {
        if ($this->docRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->docRoot;
        }

        foreach ($this->savedCsp as $key => $value) {
            Config::set($key, $value);
        }

        foreach (glob($this->certCacheDir() . '/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------- what it allows */

    public function testAValidAdminTokenSignsIn(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $answer = $this->google($this->googleToken());

        $this->assertSame(303, $answer->response->status, 'status');
        $this->assertSame(Routes::RATES, $this->location($answer), 'sent to the editor');
        $this->assertTrue($this->anySession(), 'no session row');
        $this->assertTrue(is_string($answer->cookie(Session::COOKIE)), 'no session cookie');
        $this->assertSame('google', $this->auditDetail('login')['method'] ?? null, 'audited as a Google sign-in');
        $this->assertSame(false, $this->auditDetail('login')['secondFactor'] ?? null);
    }

    public function testWithTwoFactorOnGoogleIsOnlyTheFirstStep(): void
    {
        $this->googleSignInReady();
        $this->admin(self::OWNER, 'owner', self::PASSWORD, self::SECRET);

        $first   = $this->google($this->googleToken());
        $pending = $first->cookie(PendingLogin::COOKIE);

        $this->assertSame(303, $first->response->status, 'status');
        $this->assertSame(Routes::LOGIN, $this->location($first), 'back to the code step');
        $this->assertFalse($this->anySession(), 'Google alone produced a session with 2FA on');
        $this->assertTrue(is_string($pending) && $pending !== '', 'no pending login');
        $this->assertSame('google', $this->auditDetail('login_first_factor')['method'] ?? null);

        $code = $this->totp()->codeAt(self::SECRET);
        $done = $this->postWithToken(Routes::LOGIN_CODE, ['code' => $code], [PendingLogin::COOKIE => (string) $pending]);

        $this->assertSame(Routes::RATES, $this->location($done), 'the right code');
        $this->assertTrue($this->anySession(), 'no session after both factors');
        $this->assertSame('google', $this->auditDetail('login')['method'] ?? null, 'the method survived the code step');
        $this->assertSame(true, $this->auditDetail('login')['secondFactor'] ?? null);
    }

    public function testAJsonBodyIsAcceptedUnderEitherName(): void
    {
        $this->googleSignInReady();
        $this->admin();

        foreach (['idToken', 'credential'] as $name) {
            Db::exec('DELETE FROM sessions');

            $answer = $this->googleJson((string) json_encode([$name => $this->googleToken()]));

            $this->assertSame(303, $answer->response->status, $name . ': status');
            $this->assertTrue($this->anySession(), $name . ': no session');
        }
    }

    public function testASignInFromSecondsAgoIsFresh(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $answer = $this->google($this->googleToken(self::OWNER, ['auth_time' => Clock::timestamp() - 10]));

        $this->assertSame(303, $answer->response->status, 'ten seconds old');
    }

    /* ------------------------------------------------------ what it refuses */

    public function testAStrangersValidTokenReadsLikeAForgedOne(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $answer = $this->google($this->googleToken('stranger@example.com'));

        $this->assertRefused($answer, 'a valid Google account that is not an admin');
    }

    public function testAnUnverifiedAddressIsRefused(): void
    {
        $this->googleSignInReady();
        $this->admin();

        // The string "true" is what a loose check would wave through.
        foreach ([false, 'true'] as $value) {
            $this->assertRefused(
                $this->google($this->googleToken(self::OWNER, ['email_verified' => $value])),
                'email_verified ' . var_export($value, true),
            );
        }
    }

    public function testAnotherProjectsTokenIsRefused(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $this->assertRefused($this->google($this->googleToken(self::OWNER, ['aud' => 'someone-else'])), 'wrong aud');
        $this->assertRefused(
            $this->google($this->googleToken(self::OWNER, ['iss' => 'https://securetoken.google.com/someone-else'])),
            'wrong iss',
        );
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $now = Clock::timestamp();

        $this->assertRefused(
            $this->google($this->googleToken(self::OWNER, ['iat' => $now - 7200, 'auth_time' => $now - 7200, 'exp' => $now - 3600])),
            'expired an hour ago',
        );
    }

    public function testATokenFromAnotherSignInProviderIsRefused(): void
    {
        $this->googleSignInReady();
        $this->admin();

        // The owner's own address, verified - but not signed in through Google.
        foreach (['password', 'emailLink'] as $provider) {
            $token = $this->googleToken(self::OWNER, ['firebase' => ['sign_in_provider' => $provider]]);

            $this->assertRefused($this->google($token), 'sign_in_provider ' . $provider);
        }
    }

    public function testAStaleSignInIsRefused(): void
    {
        $this->googleSignInReady();
        $this->admin();

        // A token refreshed a moment ago for a sign-in ten minutes old: what a
        // token lifted from somebody's browser looks like.
        $now   = Clock::timestamp();
        $token = $this->googleToken(self::OWNER, ['auth_time' => $now - 600, 'iat' => $now - 5]);

        $this->assertRefused($this->google($token), 'auth_time ten minutes ago');
    }

    public function testGoogleAttemptsAreRateLimitedPerAccount(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $limit = max(3, Config::int('limits.login_per_15min', 10));

        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(303, $this->google($this->googleToken())->response->status, 'attempt ' . ($i + 1));
        }

        $this->assertSame(429, $this->google($this->googleToken())->response->status, 'attempt ' . ($limit + 1));
    }

    /**
     * The bucket the password path fills is not the one Google draws from.
     * With one shared bucket, anybody who typed ten wrong passwords at the
     * owner's address locked the owner out of Google as well (2026-10-26).
     */
    public function testWrongPasswordsDoNotSpendTheGoogleAllowance(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $limit = max(3, Config::int('limits.login_per_15min', 10));

        for ($i = 0; $i < $limit; $i++) {
            $this->postWithToken(Routes::LOGIN_PASSWORD, ['email' => self::OWNER, 'password' => 'wrong-' . $i], []);
        }

        $answer = $this->google($this->googleToken());

        $this->assertSame(303, $answer->response->status, 'the owner was locked out of Google by a password guesser');
    }

    /* ----------------------------------------------------- could not check */

    public function testNoCertificatesIsA503AboutTheServer(): void
    {
        $this->googleSignInReady();
        $this->admin();

        // No cache, and a fetch that failed seconds ago: GoogleCertificates
        // does not try again within the minute, so this is exactly what an
        // outage looks like - with no network involved.
        @unlink($this->certCacheFile());
        file_put_contents(substr($this->certCacheFile(), 0, -5) . '.fail', (string) Clock::timestamp());

        $answer = $this->google($this->googleToken());

        $this->assertSame(503, $answer->response->status, 'status');
        $this->assertStringContains('could not reach Google', $this->notice($answer), 'the server sentence');
        $this->assertStringNotContains('refused', $this->notice($answer), 'not the forged-token sentence');
        $this->assertSame('60', $answer->header('Retry-After'));
        $this->assertFalse($this->anySession(), 'a session without a verified token');
        $this->assertNull($answer->cookie(Session::COOKIE));

        if ($this->logReadable) {
            $this->assertStringContains('"event":"login_google_unavailable"', $this->logSince(), 'the operator\'s clue');
        }

        // And with the cache back, a forged token is still the 401.
        $this->googleSignInReady();
        $forged = $this->googleToken(self::OWNER);
        $forged = substr($forged, 0, strrpos($forged, '.') + 1) . 'bm90LWEtc2lnbmF0dXJl';
        $this->assertRefused($this->google($forged), 'forged, with certificates in hand');
    }

    public function testACacheInsideTheDocumentRootIsA503Too(): void
    {
        $this->googleSignInReady();
        $this->admin();

        // A configuration error, not a bad token: the same 503.
        $_SERVER['DOCUMENT_ROOT'] = dirname($this->certCacheDir());

        $answer = $this->google($this->googleToken());

        $this->assertSame(503, $answer->response->status, 'status');
        $this->assertFalse($this->anySession());
    }

    /* ------------------------------------------------------------------ CSP */

    public function testTheSignInPageAndOnlyItTrustsFirebase(): void
    {
        $this->googleSignInReady();

        $login = $this->cspOf($this->get(Routes::LOGIN));

        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-[^']+' https:\\/\\/www\\.gstatic\\.com https:\\/\\/apis\\.google\\.com(;|$)/", $login), 'script-src: ' . $login);
        $this->assertStringContains('https://identitytoolkit.googleapis.com', $login);
        $this->assertStringContains('https://securetoken.googleapis.com', $login);
        $this->assertStringContains("frame-src 'self' https://" . self::FIREBASE_PROJECT . '.firebaseapp.com https://accounts.google.com', $login);

        $home = $this->cspOf($this->get('/'));

        foreach (['gstatic', 'apis.google.com', 'firebaseapp', 'googleapis'] as $host) {
            $this->assertStringNotContains($host, $home, 'the public site trusts ' . $host);
        }
        $this->assertStringContains("connect-src 'self';", $home);
    }

    public function testUnconfiguredOrMalformedFirebaseLeavesThePlainPolicy(): void
    {
        $plain = $this->cspOf($this->get(Routes::LOGIN));
        $this->assertStringNotContains('gstatic', $plain, 'Firebase unconfigured');
        $this->assertStringContains("frame-src 'self'", $plain);

        // All four set, but an auth domain that is not a hostname: no sources
        // from it, and no button whose popup the policy would block.
        $this->googleSignInReady();
        Config::set('auth.firebase_auth_domain', "x.firebaseapp.com 'unsafe-inline'");

        $answer = $this->get(Routes::LOGIN);
        $csp    = $this->cspOf($answer);

        $this->assertStringNotContains('unsafe-inline', explode('style-src', $csp)[0]);
        $this->assertStringNotContains('gstatic', $csp);
        $this->assertStringNotContains('id="firebase-config"', $answer->body());
    }

    public function testConfigExtrasReachTheSignInPageOnly(): void
    {
        $this->googleSignInReady();
        Config::set('security.csp_script', 'https://extra.example https://www.gstatic.com');

        $login = $this->cspOf($this->get(Routes::LOGIN));
        $this->assertStringContains('https://extra.example', $login);
        $this->assertSame(1, substr_count($login, 'https://www.gstatic.com'), 'a source named twice is listed once');

        $this->assertStringNotContains('extra.example', $this->cspOf($this->get('/')));
    }

    /* ------------------------------------------------------------ internals */

    private function google(string $token): Answer
    {
        return $this->postWithToken(Routes::LOGIN_GOOGLE, ['idToken' => $token], []);
    }

    /**
     * The token in a JSON body. The CSRF field still rides in the form fields,
     * because Csrf::assert() reads nothing else - a synthetic request can carry
     * both, which no browser POST can (see the report on 2026-10-26).
     */
    private function googleJson(string $body): Answer
    {
        $page    = $this->get(Routes::LOGIN);
        $cookies = [];
        $secret  = $page->cookie(Session::ANON_COOKIE);

        if (is_string($secret)) {
            $cookies[Session::ANON_COOKIE] = $secret;
        }

        preg_match('/name="' . Csrf::FIELD . '" value="([^"]*)"/', $page->body(), $m);

        InputStream::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', InputStream::class);

        try {
            return $this->send(Request::synthetic(
                'POST',
                Routes::LOGIN_GOOGLE,
                [Csrf::FIELD => $m[1] ?? ''],
                [],
                ['content-type' => 'application/json', 'content-length' => (string) strlen($body)],
                $cookies,
            ));
        } finally {
            stream_wrapper_restore('php');
            InputStream::$body = '';
        }
    }

    private function assertRefused(Answer $answer, string $why): void
    {
        $this->assertSame(401, $answer->response->status, $why . ': status');
        $this->assertSame(self::REFUSED, $this->notice($answer), $why . ': worded like every other refusal');
        $this->assertFalse($this->anySession(), $why . ': a session was started');
        $this->assertNull($answer->cookie(Session::COOKIE), $why . ': a session cookie was set');
        $this->assertNull($answer->cookie(PendingLogin::COOKIE), $why . ': a pending login was started');
    }

    /** The CSP App::send() writes onto this answer's response. */
    private function cspOf(Answer $answer): string
    {
        ob_start();

        try {
            $answer->app->send($answer->response);
        } finally {
            ob_end_clean();
        }

        return $answer->response->headers()['Content-Security-Policy'] ?? '';
    }

    /** Same as AdminLoginTest: read the page, take its token and cookie, post. @param array<string,mixed> $post @param array<string,string> $cookies */
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

    private function logFile(): string
    {
        return Paths::logs() . '/app-' . Clock::utcNow()->format('Y-m-d') . '.log';
    }

    private function logLength(): int
    {
        clearstatcache();

        return is_file($this->logFile()) ? (int) filesize($this->logFile()) : 0;
    }

    private function logSince(): string
    {
        clearstatcache();
        $all = is_file($this->logFile()) ? (string) file_get_contents($this->logFile()) : '';

        return substr($all, $this->logOffset);
    }
}
