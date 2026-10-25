<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Config;
use Speedat\Core\Request;
use Speedat\Tests\TestCase;

/**
 * The one place the application reads what arrived.
 *
 * Two things here are security, not convenience: every field is length-capped
 * on the way out, and the client IP is only taken from a header when the
 * configuration says a proxy is in front. Believing X-Forwarded-For without
 * one lets a single visitor spend everybody else's rate limit.
 */
final class RequestTest extends TestCase
{
    private bool $trustProxyWas = false;

    /** @var array<array-key,mixed> */
    private array $proxyIpsWere = [];

    public function setUp(): void
    {
        $this->trustProxyWas = Config::bool('app.trust_proxy', false);
        $this->proxyIpsWere  = Config::arr('app.proxy_ips');
    }

    public function tearDown(): void
    {
        Config::set('app.trust_proxy', $this->trustProxyWas);
        Config::set('app.proxy_ips', $this->proxyIpsWere);
    }

    public function testFromGlobalsReadsTheMethodPathAndHeaders(): void
    {
        $request = $this->fromGlobals([
            'REQUEST_METHOD'  => 'post',
            'REQUEST_URI'     => '/quote/united-kingdom?weight=500',
            'SCRIPT_NAME'     => '/index.php',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_X_TEST'     => 'yes',
            'CONTENT_TYPE'    => 'application/x-www-form-urlencoded',
            'REMOTE_ADDR'     => '203.0.113.9',
        ]);

        $this->assertSame('POST', $request->method, 'the method is upper-cased');
        $this->assertSame('/quote/united-kingdom', $request->path, 'the query string is not part of the path');
        $this->assertTrue($request->isPost());
        $this->assertFalse($request->isGet());

        // Header names arrive as HTTP_X_TEST and are read as x-test.
        $this->assertSame('yes', $request->header('X-Test'), 'header lookup ignores case and underscores');
        $this->assertSame('yes', $request->header('x-test'));
        $this->assertSame('application/x-www-form-urlencoded', $request->header('content-type'), 'CONTENT_TYPE is a header too');
        $this->assertSame('', $request->header('x-absent'));
        $this->assertSame('fallback', $request->header('x-absent', 'fallback'));
        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function testAnAbsurdMethodIsTreatedAsGet(): void
    {
        foreach (['', 'G', 'NOTAMETHODATALL', 'GET"', '../..'] as $method) {
            $request = $this->fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/']);

            $this->assertSame('GET', $request->method, 'method "' . $method . '" is not one');
        }
    }

    public function testASubdirectoryInstallLosesItsBasePath(): void
    {
        $request = $this->fromGlobals([
            'REQUEST_URI' => '/staging/public/quote',
            'SCRIPT_NAME' => '/staging/public/index.php',
        ]);

        $this->assertSame('/quote', $request->path);
        $this->assertSame('/staging/public', $request->basePath);
    }

    public function testFieldsAreTrimmedAndCapped(): void
    {
        $request = Request::synthetic('POST', '/contact', [
            'name'  => '  Zulqarnain  ',
            'long'  => str_repeat('x', 9000),
            'count' => '42',
            'array' => ['a', 'b'],
        ], ['q' => '  spaced  ']);

        $this->assertSame('Zulqarnain', $request->post('name'));
        $this->assertSame('spaced', $request->query('q'));
        $this->assertSame(5000, strlen($request->post('long')), 'the default cap is 5000 characters');
        $this->assertSame(10, strlen($request->post('long', '', 10)), 'and it can be tightened per field');
        $this->assertSame(42, $request->postInt('count'));
        $this->assertSame(7, $request->postInt('missing', 7));
        $this->assertSame('', $request->post('array'), 'an array is not a string field');
        $this->assertSame(['a', 'b'], $request->postList('array'));
        $this->assertSame([], $request->postList('name'), 'a string is not a list');
    }

    /**
     * A malformed byte is made readable on the way in (audit round 1, finding 4).
     *
     * utf8mb4 plus STRICT_TRANS_TABLES makes invalid UTF-8 an ERROR out of PDO,
     * which is a 500 page. `"Ali\xC3\x28 Khan"` - twelve bytes of
     * `curl --data-binary` at the public contact form - went through post(),
     * through Str::limit(), through Caps::problems() (which reported no problem)
     * and came back as `SQLSTATE[22007]: 1366 Incorrect string value`.
     *
     * This layer SCRUBS rather than refuses: a broken byte is a broken client,
     * not a person to argue with, and whatever is left still faces the field's own
     * validation. Core\Caps refuses with a sentence for anything that reaches a
     * write path from somewhere other than a request.
     */
    public function testAMalformedFieldIsMadeReadableRatherThanReachingTheDatabase(): void
    {
        $request = Request::synthetic('POST', '/contact', [
            'name'    => "Ali\xC3\x28 Khan",
            'message' => "Kar\xE2\x82",             // a cut-off sequence, the everyday case
            'list'    => ["one\xFF", 'two'],
        ], [
            'q' => "search\xC3",
        ]);

        foreach (['name', 'message'] as $field) {
            $value = $request->post($field);
            $this->assertTrue(mb_check_encoding($value, 'UTF-8'), $field . ' must be well-formed UTF-8 after post()');
        }

        $this->assertTrue(mb_check_encoding($request->query('q'), 'UTF-8'), 'a query-string value too');

        foreach ($request->postList('list') as $item) {
            $this->assertTrue(mb_check_encoding($item, 'UTF-8'), 'and every member of a list');
        }

        // The readable part survives - this is a repair, not a rejection.
        $this->assertStringContains('Ali', $request->post('name'));
        $this->assertStringContains('Khan', $request->post('name'));
        $this->assertStringContains('Kar', $request->post('message'));

        // Valid multi-byte text is untouched, byte for byte.
        $urdu = 'کراچی سے لندن 🚚';
        $this->assertSame($urdu, Request::synthetic('POST', '/contact', ['name' => $urdu])->post('name'));
    }

    /**
     * Scrubbing happens BEFORE the cap, so the cap cannot cut a multi-byte
     * character in half and put a malformed byte back at the end - which would
     * undo the whole exercise, at exactly the length a long message hits.
     */
    public function testTheCapCannotReintroduceABrokenByte(): void
    {
        // 4-byte characters against a cap that is not a multiple of four.
        $request = Request::synthetic('POST', '/contact', ['message' => str_repeat('🚚', 50)]);

        $capped = $request->post('message', '', 7);

        $this->assertTrue(mb_check_encoding($capped, 'UTF-8'), 'still well-formed at an awkward cap');
        $this->assertSame(7, mb_strlen($capped, 'UTF-8'), 'the cap counts characters, as Str::limit does');
        $this->assertSame(28, strlen($capped), 'and no character was cut in half');
    }

    public function testACheckboxIsPresentOrAbsentAndNothingElse(): void
    {
        $request = Request::synthetic('POST', '/admin', [
            'on'    => 'on',
            'one'   => '1',
            'zero'  => '0',
            'off'   => 'off',
            'blank' => '',
        ]);

        $this->assertTrue($request->postBool('on'));
        $this->assertTrue($request->postBool('one'));
        $this->assertFalse($request->postBool('zero'));
        $this->assertFalse($request->postBool('off'));
        $this->assertFalse($request->postBool('blank'));
        $this->assertFalse($request->postBool('absent'));
    }

    public function testAForwardedForHeaderIsIgnoredUnlessAProxyIsConfigured(): void
    {
        Config::set('app.trust_proxy', false);
        Config::set('app.proxy_ips', []);

        $spoofed = $this->fromGlobals([
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ]);

        $this->assertSame('198.51.100.7', $spoofed->ip(), 'anybody can send that header');

        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', ['198.51.100.7']);

        $behindProxy = $this->fromGlobals([
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 9.9.9.9',
        ]);

        // THE RIGHT-MOST entry, not the left-most. Every proxy appends the
        // address it received from, so 9.9.9.9 is what our own proxy saw and
        // "1.2.3.4" is a string the visitor typed. Reading the left-hand end -
        // which this used to do - is reading the attacker's own input.
        $this->assertSame('9.9.9.9', $behindProxy->ip(), 'the nearest proxy wrote the right-most entry');

        // Junk in a trusted header still does not become an address.
        $junk = $this->fromGlobals([
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ]);

        $this->assertSame('198.51.100.7', $junk->ip());
    }

    /**
     * THE FINDING (audit round 1, #11). `trust_proxy = true` used to believe
     * `CF-Connecting-IP` from ANYBODY: the origin's own address is discoverable
     * behind Cloudflare, so a request sent straight to it carried whatever
     * address the attacker typed, and every per-IP limit became
     * per-attacker-chosen-string - unlimited - on the setting whose purpose was
     * to make those limits correct.
     */
    public function testASpoofedHeaderFromAnUntrustedPeerIsNotBelieved(): void
    {
        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', ['173.245.48.0/20']);   // a Cloudflare range

        // The attacker reaches the origin directly. The flag is on, the list is
        // set, and the peer is not on it.
        $direct = $this->fromGlobals([
            'REMOTE_ADDR'           => '198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
            'HTTP_X_FORWARDED_FOR'  => '1.2.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertSame('198.51.100.7', $direct->ip(), 'the handshake is the only proof of an address');
        $this->assertFalse($direct->https, 'and the same peer cannot claim to be on https either');

        // The same request through the proxy: now the header is the truth.
        $viaProxy = $this->fromGlobals([
            'REMOTE_ADDR'           => '173.245.48.9',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
            'HTTP_X_FORWARDED_FOR'  => '1.2.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertSame('5.6.7.8', $viaProxy->ip());
        $this->assertTrue($viaProxy->https);
    }

    /**
     * An empty `app.proxy_ips` believes nobody, which is what
     * `trust_proxy = false` already meant. A half-finished configuration must
     * not be a more trusting one than no configuration.
     */
    public function testTrustProxyOnItsOwnChangesNothing(): void
    {
        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', []);

        $request = $this->fromGlobals([
            'REMOTE_ADDR'           => '198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ]);

        $this->assertSame('198.51.100.7', $request->ip());

        // And a list of nonsense is a list of nothing, not a list of everything.
        Config::set('app.proxy_ips', ['', '  ', 'not-a-cidr', '198.51.100.7/999']);

        $this->assertSame('198.51.100.7', $this->fromGlobals([
            'REMOTE_ADDR'           => '198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ])->ip());
    }

    /**
     * A long chain, with two of our own hops in it. The visitor is the first
     * entry from the right that is not one of ours; the eighteen entries the
     * visitor invented to the left of that are never looked at.
     */
    public function testALongChainResolvesToTheVisitorAndNotToOurOwnHops(): void
    {
        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', ['10.0.0.0/8', '172.16.0.0/12']);

        $invented = implode(', ', array_fill(0, 18, '1.2.3.4'));

        $request = $this->fromGlobals([
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => $invented . ', 203.0.113.9, 172.16.4.4, 10.0.0.2',
        ]);

        $this->assertSame('203.0.113.9', $request->ip(), 'two trusted hops skipped, the visitor found');

        // An absurd chain is truncated from the LEFT - the end our own proxies
        // wrote is the end worth keeping - so this stays readable.
        $absurd = $this->fromGlobals([
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => implode(', ', array_fill(0, 500, '1.2.3.4')) . ', 203.0.113.9, 10.0.0.2',
        ]);

        $this->assertSame('203.0.113.9', $absurd->ip());
    }

    /**
     * IPv6, which the old code could not read at all: an entry that carries a
     * port is bracketed (`[2001:db8::1]:51000`), that failed
     * FILTER_VALIDATE_IP, and the chain was silently unusable - so every IPv6
     * visitor behind the proxy shared one rate-limit counter with every other.
     */
    public function testIpv6PeersChainsAndRangesAllWork(): void
    {
        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', ['2400:cb00::/32']);

        $bare = $this->fromGlobals([
            'REMOTE_ADDR'          => '2400:cb00:1::5',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::1',
        ]);

        $this->assertSame('2001:db8::1', $bare->ip(), 'a bare IPv6 entry');

        $withPort = $this->fromGlobals([
            'REMOTE_ADDR'          => '2400:cb00:1::5',
            'HTTP_X_FORWARDED_FOR' => '[2001:db8::2]:51000',
        ]);

        $this->assertSame('2001:db8::2', $withPort->ip(), 'and one carrying a port');

        $v4WithPort = $this->fromGlobals([
            'REMOTE_ADDR'          => '2400:cb00:1::5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9:51000',
        ]);

        $this->assertSame('203.0.113.9', $v4WithPort->ip(), 'an IPv4 entry may carry one too');

        // An IPv6 peer is not inside an IPv4 block, and an IPv4 one is not
        // inside an IPv6 block. Neither is a reason to believe a header.
        Config::set('app.proxy_ips', ['198.51.100.0/24']);
        $this->assertSame('2400:cb00:1::5', $this->fromGlobals([
            'REMOTE_ADDR'           => '2400:cb00:1::5',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ])->ip());

        // A dual-stack listener reports an IPv4 peer as ::ffff:a.b.c.d, and a
        // proxy list written the way every provider publishes it must still
        // match - otherwise the fix silently stops working on that host.
        Config::set('app.proxy_ips', ['198.51.100.0/24']);
        $this->assertSame('5.6.7.8', $this->fromGlobals([
            'REMOTE_ADDR'           => '::ffff:198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ])->ip());
    }

    /** A single address on the list is a /32 (or /128), not a prefix. */
    public function testAPlainAddressOnTheListMatchesOnlyItself(): void
    {
        Config::set('app.trust_proxy', true);
        Config::set('app.proxy_ips', ['198.51.100.7']);

        $this->assertSame('5.6.7.8', $this->fromGlobals([
            'REMOTE_ADDR'           => '198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ])->ip());

        $this->assertSame('198.51.100.8', $this->fromGlobals([
            'REMOTE_ADDR'           => '198.51.100.8',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ])->ip(), 'the neighbour is not the proxy');
    }

    public function testHttpsIsDetectedFromTheServerAndOnlyFromAHeaderWhenTrusted(): void
    {
        $this->assertFalse($this->fromGlobals(['HTTPS' => 'off'])->https);
        $this->assertTrue($this->fromGlobals(['HTTPS' => 'on'])->https);
        $this->assertTrue($this->fromGlobals(['SERVER_PORT' => '443'])->https);

        Config::set('app.trust_proxy', false);
        Config::set('app.proxy_ips', ['198.51.100.7']);
        $this->assertFalse($this->fromGlobals([
            'REMOTE_ADDR'            => '198.51.100.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->https);

        Config::set('app.trust_proxy', true);
        $this->assertTrue($this->fromGlobals([
            'REMOTE_ADDR'            => '198.51.100.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->https);
    }

    public function testTheUserAgentIsCappedToWhatTheColumnHolds(): void
    {
        $request = $this->fromGlobals(['HTTP_USER_AGENT' => str_repeat('u', 4000)]);

        $this->assertSame(300, strlen($request->userAgent()), 'sessions.user_agent is VARCHAR(300)');
    }

    public function testWantsJsonLooksAtBothHeaders(): void
    {
        $this->assertTrue(Request::synthetic('GET', '/', [], [], ['accept' => 'application/json'])->wantsJson());
        $this->assertTrue(Request::synthetic('POST', '/', [], [], ['content-type' => 'application/json'])->wantsJson());
        $this->assertFalse(Request::synthetic('GET', '/', [], [], ['accept' => 'text/html'])->wantsJson());
        $this->assertFalse(Request::synthetic('GET', '/')->wantsJson());
    }

    public function testTheOriginIsBuiltFromTheHostAndTheScheme(): void
    {
        $this->assertSame('http://books.example', Request::synthetic('GET', '/', [], [], ['host' => 'books.example'])->origin());
        $this->assertSame('http://localhost', Request::synthetic('GET', '/')->origin(), 'no Host header at all');
        $this->assertSame('https://books.example', $this->fromGlobals(['HTTPS' => 'on', 'HTTP_HOST' => 'books.example'])->origin());
    }

    /**
     * The discarded-body case, which is the reason post_max_size is now bigger
     * than upload_max_filesize in public/.htaccess.
     *
     * A form POST whose body was too large arrives with $_POST empty, $_FILES
     * empty and no error code anywhere - indistinguishable from an empty form
     * unless you look at the Content-Length the client announced.
     */
    public function testAPostWhoseBodyWasThrownAwayIsRecognised(): void
    {
        $discarded = Request::synthetic('POST', '/admin/import', [], [], [
            'content-type'   => 'multipart/form-data; boundary=----x',
            'content-length' => '9000000',
        ]);

        $this->assertTrue($discarded->postWasDiscarded());

        // Everything that is NOT that case.
        $this->assertFalse(
            Request::synthetic('POST', '/admin/import', ['_token' => 'x'], [], [
                'content-type'   => 'multipart/form-data; boundary=----x',
                'content-length' => '9000000',
            ])->postWasDiscarded(),
            'fields arrived, so the body was parsed'
        );

        $this->assertFalse(
            Request::synthetic('POST', '/admin/import', [], [], [
                'content-type'   => 'multipart/form-data; boundary=----x',
                'content-length' => '0',
            ])->postWasDiscarded(),
            'an empty body is an empty form, not a discarded one'
        );

        $this->assertFalse(
            Request::synthetic('POST', '/api/quote', [], [], [
                'content-type'   => 'application/json',
                'content-length' => '900',
            ])->postWasDiscarded(),
            'a JSON route legitimately has an empty $_POST'
        );

        $this->assertFalse(
            Request::synthetic('GET', '/', [], [], ['content-length' => '900'])->postWasDiscarded(),
            'only a POST has a body to lose'
        );

        $withFile = Request::synthetic('POST', '/admin/import', [], [], [
            'content-type'   => 'multipart/form-data; boundary=----x',
            'content-length' => '9000000',
        ], [], ['sheet' => ['name' => 'rates.xlsx', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x', 'size' => 10]]);

        $this->assertFalse($withFile->postWasDiscarded(), 'the file arrived, so nothing was discarded');
    }

    public function testFileReturnsOneUploadAndRefusesAMultipleInput(): void
    {
        $single = Request::synthetic('POST', '/admin/import', [], [], [], [], [
            'sheet' => ['name' => 'rates.xlsx', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x', 'size' => 10],
        ]);

        $this->assertSame('rates.xlsx', $single->file('sheet')['name'] ?? null);
        $this->assertNull($single->file('missing'));

        // A multiple-file input gives arrays for every member. Reading
        // tmp_name off that would hand a path-shaped array to fopen().
        $many = Request::synthetic('POST', '/admin/import', [], [], [], [], [
            'sheet' => ['name' => ['a.xlsx', 'b.xlsx'], 'error' => [0, 0], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'size' => [1, 2]],
        ]);

        $this->assertNull($many->file('sheet'), 'this site has no multiple-file input');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Request::fromGlobals() against a made-up $_SERVER.
     *
     * The superglobals are put back afterwards whatever happens: a test that
     * leaves $_SERVER rewritten breaks whichever test runs next, which is the
     * kind of failure that gets blamed on the wrong file.
     *
     * @param array<string,string> $server
     */
    private function fromGlobals(array $server): Request
    {
        $savedServer = $_SERVER;
        $savedGet    = $_GET;
        $savedPost   = $_POST;
        $savedCookie = $_COOKIE;
        $savedFiles  = $_FILES;

        try {
            $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
            $_GET    = [];
            $_POST   = [];
            $_COOKIE = [];
            $_FILES  = [];

            return Request::fromGlobals();
        } finally {
            $_SERVER = $savedServer;
            $_GET    = $savedGet;
            $_POST   = $savedPost;
            $_COOKIE = $savedCookie;
            $_FILES  = $savedFiles;
        }
    }
}
