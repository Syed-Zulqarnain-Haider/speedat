<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Caps;
use Speedat\Core\Request;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/**
 * Two ways the request snapshot used to misread what arrived.
 *
 *   1. A header with a malformed UTF-8 byte reached utf8mb4 columns as it was
 *      sent. Under STRICT_TRANS_TABLES that is a 1366: Core\Audit swallowed it
 *      and the audit line was lost, Core\Session rethrew it and sign-in was a
 *      500. Headers are now scrubbed like form fields, and Caps::fit() scrubs
 *      again as a backstop.
 *   2. `//faq` went through parse_url(), which read `faq` as a host, so the
 *      path became "/" and the home page answered 200 where Http\Kernel
 *      promises a 301 to `/faq`.
 *
 * No database: everything here is the value that WOULD be written, checked
 * the way the database would check it.
 */
final class B12RequestParsingTest extends TestCase
{
    public function testAMalformedUserAgentArrivesAsValidUtf8(): void
    {
        $request = $this->fromGlobals(['HTTP_USER_AGENT' => "caf\xE9 browser"]);

        $this->assertTrue(mb_check_encoding($request->header('user-agent'), 'UTF-8'), 'the header is scrubbed on the way in');
        $this->assertTrue(mb_check_encoding($request->userAgent(), 'UTF-8'));
        $this->assertStringContains('browser', $request->userAgent(), 'the readable part survives');

        // The value Core\Audit and Core\Session actually write.
        $stored = Caps::fit('audit_log.user_agent', $request->userAgent());
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertSame([], Caps::problems('sessions', ['user_agent' => $stored]), 'nothing a strict table would refuse');
    }

    public function testEveryHeaderSourceIsScrubbed(): void
    {
        $request = $this->fromGlobals([
            'HTTP_X_ODD'                  => "\xC3\x28",
            'CONTENT_TYPE'                => "text/plain; charset=\xFF",
            'REDIRECT_HTTP_AUTHORIZATION' => "Bearer \xE9",
        ]);

        foreach (['x-odd', 'content-type', 'authorization'] as $name) {
            $this->assertTrue(mb_check_encoding($request->header($name), 'UTF-8'), $name . ' is well-formed');
        }
    }

    public function testAWellFormedHeaderIsUntouched(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 - Lahore é ü 漢字';

        $this->assertSame($ua, $this->fromGlobals(['HTTP_USER_AGENT' => $ua])->header('user-agent'));
        $this->assertSame('Bearer abc.DEF-123', $this->fromGlobals(['HTTP_AUTHORIZATION' => 'Bearer abc.DEF-123'])->header('authorization'));
    }

    public function testACapThroughAMultiByteCharacterLeavesNoBrokenTail(): void
    {
        // 19,999 ASCII bytes and then a three-byte character: the 20,000-byte
        // cut lands inside it.
        $request = $this->fromGlobals(['HTTP_X_LONG' => str_repeat('a', 19999) . '漢漢']);

        $this->assertTrue(mb_check_encoding($request->header('x-long'), 'UTF-8'));
    }

    public function testFitScrubsAValueThatDidNotComeThroughRequest(): void
    {
        // A percent-decoded path is one: /%E9 decodes to a lone \xE9.
        $fitted = Caps::fit('page_views.path', rawurldecode('/%E9t%E9'));

        $this->assertTrue(mb_check_encoding($fitted, 'UTF-8'));
        $this->assertSame('/t', str_replace('?', '', $fitted), 'only the broken bytes are replaced');
        $this->assertSame('plain', Caps::fit('audit_log.action', 'plain'), 'a well-formed value is unchanged');
        $this->assertSame(64, mb_strlen(Caps::fit('audit_log.action', str_repeat("\xE9", 200)), 'UTF-8'), 'the cut counts characters after the scrub');
    }

    public function testADoubledLeadingSlashKeepsItsPathSoTheCanonicalRedirectCanFire(): void
    {
        $request = $this->fromGlobals(['REQUEST_URI' => '//faq']);

        $this->assertSame('//faq', $request->path, 'not read as a host called "faq"');
        $this->assertSame('/faq', Url::normalise($request->path), 'and Url::normalise() makes it the 301 target');
        $this->assertNotSame($request->path, Url::normalise($request->path), 'so Kernel::canonicalRedirect() does not see the canonical URL');
    }

    public function testThePathStopsAtTheQueryOrFragment(): void
    {
        $this->assertSame('//quote/uk', $this->fromGlobals(['REQUEST_URI' => '//quote/uk?weight=2'])->path);
        $this->assertSame('/faq', $this->fromGlobals(['REQUEST_URI' => '/faq#top'])->path);
        $this->assertSame('/a b', $this->fromGlobals(['REQUEST_URI' => '/a%20b?x=1'])->path, 'still percent-decoded');
        $this->assertSame('/', $this->fromGlobals(['REQUEST_URI' => '/?x=1'])->path);
        $this->assertSame('/', $this->fromGlobals(['REQUEST_URI' => ''])->path);
        $this->assertSame('/', $this->fromGlobals(['REQUEST_URI' => '?x=1'])->path);
    }

    public function testAnAbsoluteFormTargetStillYieldsItsPath(): void
    {
        $this->assertSame('/faq', $this->fromGlobals(['REQUEST_URI' => 'http://speedat.co/faq?x=1'])->path);
    }

    public function testASubdirectoryInstallStillLosesItsBasePath(): void
    {
        $request = $this->fromGlobals([
            'REQUEST_URI' => '/staging/public/quote?x=1',
            'SCRIPT_NAME' => '/staging/public/index.php',
        ]);

        $this->assertSame('/quote', $request->path);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Request::fromGlobals() against a made-up $_SERVER, superglobals put back
     * afterwards whatever happens - the same helper tests/Core/RequestTest.php
     * uses, for the same reason.
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
