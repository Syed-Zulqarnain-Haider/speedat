<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Clock;
use Speedat\Core\Cookies;
use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\HttpError;
use Speedat\Core\Request;
use Speedat\Core\Response;
use Speedat\Core\Session;
use Speedat\Tests\TestCase;

/**
 * The form token.
 *
 * The anonymous half needs no database - a visitor's CSRF secret lives in its
 * own cookie, which is the whole reason the public quote and contact forms can
 * be protected without giving every visitor a session row. The signed-in half
 * needs one, and skips when there is none.
 *
 * THIS SUITE USED TO FAIL ABOUT ONE RUN IN SIXTEEN, which is worse than
 * failing every time: the first report of "zero failures" was true and told
 * nobody anything. The cause was in this file, not in Csrf - see
 * testNothingElseIsAccepted.
 */
final class CsrfTest extends TestCase
{
    /** Set only by the tests that sign somebody in, so tearDown knows what to clean up. */
    private string $email = '';

    /** The cookie jar the most recent signIn() wrote into. */
    private Cookies $jar;

    public function setUp(): void
    {
        $this->email = '';
        $this->jar   = new Cookies(false);
    }

    public function tearDown(): void
    {
        Clock::freeze(null);

        if ($this->email !== '' && Db::healthy()) {
            Db::exec('DELETE FROM sessions WHERE email = ?', [$this->email]);
        }

        $this->email = '';
    }

    public function testATokenItIssuedIsATokenItAccepts(): void
    {
        $csrf  = $this->csrf();
        $token = $csrf->token();

        $this->assertTrue($csrf->check($token));
        $this->assertSame($token, $csrf->token(), 'one token per request, reused by every form on the page');
    }

    public function testNothingElseIsAccepted(): void
    {
        $csrf  = $this->csrf();
        $token = $csrf->token();

        $this->assertFalse($csrf->check(null), 'a missing token is not a valid one');
        $this->assertFalse($csrf->check(''));
        $this->assertFalse($csrf->check('nonsense'));

        // THE FLAKE WAS HERE. The line used to be
        //     substr($token, 0, -1) . '0'
        // which changes nothing at all when the signature already ends in "0".
        // The signature is hex, so that is one run in sixteen, and on those
        // runs the "corrupted" token WAS the real token and check() rightly
        // returned true. Changing the last character to one it is not makes
        // the corruption certain, and the assertion below now means what it
        // says on every run.
        $last    = substr($token, -1);
        $flipped = substr($token, 0, -1) . ($last === '0' ? '1' : '0');

        $this->assertNotSame($token, $flipped, 'the corrupted token must actually differ from the real one');
        $this->assertFalse($csrf->check($flipped), 'a flipped signature byte');

        // The same nonce re-signed by hand, to prove the signature is what is
        // being checked and not the shape of the string.
        [$issuedAt, $nonce, $signature] = explode('.', $token);
        $this->assertFalse($csrf->check($issuedAt . '.' . $nonce . '.' . str_repeat('a', 32)));

        // And every position of the signature, changed one at a time, rather
        // than one sampled character. This is what the flaky line was reaching
        // for, and it cannot be a no-op for any of them.
        for ($i = 0, $n = strlen($signature); $i < $n; $i++) {
            $broken     = $signature;
            $broken[$i] = $signature[$i] === 'a' ? 'b' : 'a';

            $this->assertFalse(
                $csrf->check($issuedAt . '.' . $nonce . '.' . $broken),
                'character ' . $i . ' of the signature is not being checked'
            );
        }
    }

    public function testATokenFromAnotherVisitorIsRefused(): void
    {
        $mine   = $this->csrf();
        $theirs = $this->csrf();

        // Two visitors, two anonymous secrets, so the tokens are not
        // interchangeable - which is what stops a token being farmed from one
        // browser and posted from another.
        $this->assertFalse($mine->check($theirs->token()));
        $this->assertFalse($theirs->check($mine->token()));
    }

    public function testATokenExpires(): void
    {
        Clock::freeze('2026-01-01 09:00:00');

        $csrf  = $this->csrf();
        $token = $csrf->token();
        $this->assertTrue($csrf->check($token));

        // Eleven hours later the tab is still usable.
        Clock::freeze('2026-01-01 20:00:00');
        $this->assertTrue($csrf->check($token));

        // Thirteen hours later it is not: the limit is twelve.
        Clock::freeze('2026-01-01 22:00:01');
        $this->assertFalse($csrf->check($token), 'a token older than twelve hours is refused');
    }

    public function testACrossSiteOriginIsRejected(): void
    {
        $csrf = $this->csrf();

        $this->assertTrue(
            $csrf->originOk(Request::synthetic('POST', '/quote', [], [], [])),
            'no Origin header at all is allowed - the token is the real protection'
        );

        $this->assertFalse(
            $csrf->originOk(Request::synthetic('POST', '/quote', [], [], ['origin' => 'https://evil.example'])),
            "someone else's Origin is refused"
        );

        // The host the request actually arrived on is always acceptable, so a
        // www/apex mismatch with app.url cannot present as "this form expired"
        // on every form in the admin.
        $this->assertTrue(
            $csrf->originOk(Request::synthetic('POST', '/quote', [], [], [
                'origin' => 'http://books.example',
                'host'   => 'books.example',
            ])),
            'our own host is our own origin'
        );
    }

    /**
     * `Origin: null` is NOT the same as no Origin, and used to be treated as if
     * it were (audit round 1, finding 16).
     *
     * Absence is a client that did not send the header - allowed, because the
     * token is the real protection and a privacy extension is not an attacker.
     * The literal string `null` is what a sandboxed iframe, a `data:` document
     * and some cross-origin redirect chains send, which makes it a value an
     * attacker can arrange rather than one a visitor happens to have. No browser
     * sends it for a form posted from a page on this site, so refusing it costs
     * nothing.
     */
    public function testTheLiteralNullOriginIsRefusedWhileAnAbsentOneIsAllowed(): void
    {
        $csrf = $this->csrf();

        foreach (['null', 'NULL', 'Null'] as $spelling) {
            $this->assertFalse(
                $csrf->originOk(Request::synthetic('POST', '/quote', [], [], ['origin' => $spelling])),
                'Origin: ' . $spelling . ' is a value an attacker can choose'
            );
        }

        $this->assertTrue(
            $csrf->originOk(Request::synthetic('POST', '/quote', [], [], [])),
            'and absence still is not'
        );

        // And it is refused all the way up: assert() is what the routes call.
        $blocked = Request::synthetic('POST', '/quote', [Csrf::FIELD => $csrf->token()], [], ['origin' => 'null']);

        $error = $this->assertThrows(
            HttpError::class,
            static fn (): null => $csrf->assert($blocked) ?? null,
            'a valid token does not rescue a null Origin'
        );

        // 403, and it was 419 until the go-live rehearsal measured that status
        // arriving as a 500 over real Apache. See Core\HttpError::invalidToken().
        $this->assertSame(403, $error->status ?? 0);
        $this->assertTrue(
            Response::isTransportable($error->status ?? 0),
            'a refusal no server can put on the wire as itself reads as an outage'
        );
    }

    /**
     * The order bug, from the side the admin saw it.
     *
     * Csrf asks Session for the secret when it issues a token, and asks again
     * when it verifies one. Session used to answer with the anonymous cookie
     * until something had read the sessions row, and with the session token
     * afterwards - so the two answers differed whenever a token was issued
     * before anything else touched the session, and a correctly filled form
     * came back "expired". Both orders are checked here because only one of
     * them was ever broken, and it was the one the admin pages use.
     */
    public function testIssuingBeforeAnythingTouchesTheSessionStillVerifies(): void
    {
        $this->requireDatabase();

        // Order A: issue FIRST, and only then read anything about the session.
        $signedIn = $this->signIn('order-a@example.test');
        $csrfA    = new Csrf($signedIn);
        $tokenA   = $csrfA->token();

        $this->assertTrue($signedIn->isAuthenticated(), 'the session is real');
        $this->assertTrue($csrfA->check($tokenA), 'a token issued before the session was read must still verify');

        // Order B: read the session first, then issue. This order always worked.
        $sessionB = $this->reopen();
        $csrfB    = new Csrf($sessionB);
        $this->assertTrue($sessionB->isAuthenticated());
        $tokenB = $csrfB->token();
        $this->assertTrue($csrfB->check($tokenB));

        // And the two orders have to agree with EACH OTHER, which is the
        // property that actually matters: the request that renders the form
        // and the request that posts it are not the same request.
        $verifier = new Csrf($this->reopen());
        $this->assertTrue($verifier->check($tokenA), 'issued in order A, verified in a later request');
        $this->assertTrue($verifier->check($tokenB), 'issued in order B, verified in a later request');

        // The secret itself, asked for in both orders, is one value.
        $readFirst = $this->reopen();
        $readFirst->isAuthenticated();
        $issueFirst = $this->reopen();

        $this->assertSame(
            $readFirst->secret(),
            $issueFirst->secret(),
            'the secret cannot depend on what else the request happened to do first'
        );
    }

    /**
     * And across a rotation: the second factor issues a new session id, and
     * the page rendered immediately afterwards must still carry a usable
     * token.
     */
    public function testATokenSurvivesASessionIdRotation(): void
    {
        $this->requireDatabase();

        $session = $this->signIn('rotate@example.test');
        $csrf    = new Csrf($session);
        $before  = $csrf->token();

        $session->rotate();

        $this->assertTrue($csrf->check($before), 'the token issued before the rotation still verifies after it');

        // The cookie really did change - this is a rotation, not a no-op.
        $ids = [];
        foreach ($this->jar->queued() as $cookie) {
            if ($cookie['name'] === Session::COOKIE && $cookie['value'] !== '') {
                $ids[] = $cookie['value'];
            }
        }
        $this->assertCount(2, array_unique($ids), 'sign-in and rotation set two different session ids');

        // A request arriving with the NEW cookie verifies the OLD token: the
        // secret belongs to the session, not to the cookie.
        $next = new Csrf($this->reopen());
        $this->assertTrue($next->check($before));
    }

    /** Signing out kills every form that was open, which rotation deliberately does not. */
    public function testSigningOutInvalidatesTheTokensItIssued(): void
    {
        $this->requireDatabase();

        $session = $this->signIn('bye@example.test');
        $csrf    = new Csrf($session);
        $token   = $csrf->token();
        $this->assertTrue($csrf->check($token));

        $session->destroy();

        $after = new Csrf($this->reopen());
        $this->assertFalse($after->check($token), 'the row is gone, so the secret is gone');
    }

    // ---------------------------------------------------------------- helpers

    /** A fresh anonymous visitor: no cookies, so Session mints a new CSRF secret. */
    private function csrf(): Csrf
    {
        $request = Request::synthetic('GET', '/');

        return new Csrf(new Session($request, new Cookies(false)));
    }

    /** Sign an address in, keeping the cookie jar so the token can be read back. */
    private function signIn(string $email): Session
    {
        $this->email = $email;
        $this->jar   = new Cookies(false);

        $session = new Session(Request::synthetic('POST', '/admin/sign-in'), $this->jar);
        $session->start($email);

        return $session;
    }

    /**
     * The next request from the same browser: a new Session built from the
     * cookie the last one set. This is what makes "both orders" a real test
     * rather than two calls on one warm object.
     */
    private function reopen(): Session
    {
        $token = $this->jar->queuedValue(Session::COOKIE) ?? '';

        return new Session(
            Request::synthetic('GET', '/admin', [], [], [], [Session::COOKIE => $token]),
            new Cookies(false)
        );
    }

    private function requireDatabase(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }
    }
}
