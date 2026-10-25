<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Core\Response;
use Speedat\Tests\TestCase;

/**
 * What goes back, and the one header that is decided rather than set.
 *
 * THE BUG THIS FILE IS MOSTLY ABOUT. Response sets Content-Length itself, which
 * is right until something compresses the body afterwards. `zlib.output_compression`
 * is a php.ini setting, so it can be on for the production host and off for this
 * machine; PHP gzips the body on the way out and does NOT correct a
 * Content-Length the application set. The browser then waits for strlen($body)
 * bytes out of a much shorter compressed stream, gives up, and renders a
 * truncated page - on that host only, on every page, with nothing in any log.
 *
 * Response::bodyLengthSurvives() is the guard, and it can only be checked
 * honestly from a process where the flag is really on, which is why
 * tests/Core/response-child.php exists and is run four ways below. send() itself
 * is not directly observable at all under CLI - header() does nothing and
 * headers_list() comes back empty - which is why headersToSend() is separate.
 */
final class ResponseTest extends TestCase
{
    // ------------------------------------------------- Content-Length, for real

    public function testContentLengthIsSetWhenNothingWillCompressTheBody(): void
    {
        $report = $this->childReport([]);

        // The flag's default spelling depends on the build: '' where a php.ini
        // says Off (the host), '0' with no php.ini at all (the Docker image).
        // Both are off; what matters is that the child did not turn it on.
        $this->assertTrue(in_array($report['zlib'], ['', '0'], true), 'no compression in this child, got "' . $report['zlib'] . '"');
        $this->assertTrue($report['survives']);

        // strlen(), the byte count - not mb_strlen(), which would promise fewer
        // bytes than the body actually is the moment a page contains one Urdu
        // character, and truncate it in the browser by exactly that much.
        $this->assertSame((string) $report['bytes'], $report['headers']['Content-Length'] ?? null);
        $this->assertTrue(
            $report['bytes'] > $report['chars'],
            'the child body really is multibyte, so byte and character counts differ: '
            . $report['bytes'] . ' bytes, ' . $report['chars'] . ' characters'
        );
    }

    /**
     * The failure case, in a process that really has the flag on.
     */
    public function testContentLengthIsWithheldWhenPhpWillGzipTheBody(): void
    {
        $report = $this->childReport(['-d', 'zlib.output_compression=1']);

        $this->assertSame('1', $report['zlib'], 'the child really was started with it on');
        $this->assertFalse($report['survives']);
        $this->assertFalse(
            isset($report['headers']['Content-Length']),
            'a Content-Length PHP is about to invalidate is worse than none'
        );

        // Everything else still goes out.
        $this->assertSame('text/html; charset=utf-8', $report['headers']['Content-Type'] ?? null);
    }

    /**
     * The spelling that a boolean test gets wrong.
     *
     * zlib.output_compression may be a BUFFER SIZE - "4096" means on with a 4 KB
     * buffer - and filter_var($v, FILTER_VALIDATE_BOOL) reads "4096" as false.
     * That is the exact shape of the bug: compression on, Content-Length sent,
     * truncated pages, and a guard that said everything was fine.
     */
    public function testABufferSizeMeansOnAndNotFalse(): void
    {
        $report = $this->childReport(['-d', 'zlib.output_compression=4096']);

        $this->assertSame('4096', $report['zlib']);
        $this->assertFalse($report['survives'], '"4096" means ON, whatever a boolean filter thinks');
        $this->assertFalse(isset($report['headers']['Content-Length']));
    }

    /**
     * And the other compressor: a gzipping output-buffer handler rather than the
     * ini flag. mod_deflate and LiteSpeed are not a problem - they drop the
     * header and switch to chunked - so these two are the only cases that have
     * to be noticed.
     */
    public function testAGzippingOutputBufferIsNoticedToo(): void
    {
        $report = $this->childReport([], 'gzhandler');

        $this->assertTrue(in_array($report['zlib'], ['', '0'], true), 'this one is not the ini flag, got "' . $report['zlib'] . '"');
        $this->assertTrue(in_array('ob_gzhandler', $report['handlers'], true), 'the handler really is on the stack');
        $this->assertFalse($report['survives']);
        $this->assertFalse(isset($report['headers']['Content-Length']));
    }

    /** "Off" is off, and must not be read as the non-empty string it is. */
    public function testTheOffSpellingsAreOff(): void
    {
        foreach (['Off', '0', 'off'] as $spelling) {
            $report = $this->childReport(['-d', 'zlib.output_compression=' . $spelling]);

            $this->assertTrue($report['survives'], '"' . $spelling . '" is not compression');
            $this->assertSame((string) $report['bytes'], $report['headers']['Content-Length'] ?? null);
        }
    }

    /**
     * A HEAD response carries every header the GET would have - except the one
     * that would describe a body it is not sending.
     */
    public function testAHeadResponseGetsNoContentLength(): void
    {
        $report = $this->childReport([]);

        $this->assertSame('text/html; charset=utf-8', $report['headHeaders']['Content-Type'] ?? null);
        $this->assertFalse(isset($report['headHeaders']['Content-Length']));
    }

    // ------------------------------------------------------- the rest, in-process

    public function testAnExplicitContentLengthIsLeftAlone(): void
    {
        $headers = Response::text('12345')->header('Content-Length', '5')->headersToSend();

        $this->assertSame('5', $headers['Content-Length']);
    }

    public function testEachConstructorSetsItsOwnContentType(): void
    {
        $this->assertSame('text/html; charset=utf-8', Response::html('x')->headers()['Content-Type']);
        $this->assertSame('text/plain; charset=utf-8', Response::text('x')->headers()['Content-Type']);
        $this->assertSame('application/json; charset=utf-8', Response::json([])->headers()['Content-Type']);

        $this->assertSame(200, Response::html('x')->status);
        $this->assertSame(404, Response::html('x', 404)->status);
    }

    public function testJsonKeepsSlashesAndUnicodeReadable(): void
    {
        $body = Response::json(['path' => '/quote/oman', 'city' => 'کراچی'])->body();

        $this->assertStringContains('"/quote/oman"', $body, 'an escaped slash in a URL is noise');
        $this->assertStringContains('کراچی', $body, 'and \\u escapes make a log unreadable');
    }

    /**
     * Unencodable input must not become an empty response with a 200 - "{}" is
     * at least honest JSON.
     */
    public function testJsonThatCannotBeEncodedStillProducesJson(): void
    {
        $this->assertSame('{}', Response::json(fopen('php://memory', 'rb'))->body());
    }

    /**
     * The single place in the application that could become an open redirect.
     * Anything that is not a same-origin absolute path becomes "/".
     */
    public function testRedirectRefusesToLeaveTheSite(): void
    {
        $this->assertSame('/thanks', Response::redirect('/thanks')->headers()['Location']);
        $this->assertSame(303, Response::redirect('/thanks')->status, 'a POST must be followed by a GET');
        $this->assertSame(301, Response::redirect('/thanks', 301)->status);

        foreach (['//evil.test/phish', 'https://evil.test', 'http://evil.test', 'evil.test', '', 'javascript:alert(1)'] as $hostile) {
            $this->assertSame('/', Response::redirect($hostile)->headers()['Location'], 'refused: ' . $hostile);
        }
    }

    /**
     * THE OPEN REDIRECT, in the fifteen forms that were measured against the
     * old guard (audit round 1, finding 1).
     *
     * `curl --path-as-is 'http://host/%5Cevil.example/'` used to answer
     * `301 Location: /\evil.example`, and Chrome landed on evil.example -
     * because the guard only knew about "//" and ran BEFORE header() stripped
     * the newline out of "/\n/evil.example" to leave "//evil.example".
     *
     * The right-hand column is what the value must be now. Every one of the
     * hostile forms is "/" - not a cleaned-up version of itself, which would be
     * a path nobody asked for.
     */
    public function testRedirectRefusesEveryFormOfAnAuthority(): void
    {
        $cases = [
            // legitimate, and must keep working
            '/admin'                => '/admin',
            '/admin?x=1'            => '/admin?x=1',
            '/a/b/c'                => '/a/b/c',
            '/faq?ref=whatsapp+share' => '/faq?ref=whatsapp+share',
            '/'                     => '/',
            '/quote#total'          => '/quote#total',
            // a percent-encoded backslash is a path character to every browser
            // - it is only the DECODED one that becomes an authority - so this
            // must NOT be thrown away.
            '/%5Cevil.example'      => '/%5Cevil.example',
            // a scheme INSIDE the path is still this origin: the browser
            // resolves it to https://host/https://evil.example
            '/https://evil.example' => '/https://evil.example',

            // hostile
            ''                      => '/',
            '//evil.example'        => '/',
            '/\\evil.example'       => '/',   // the WHATWG backslash authority
            '/\\\\evil.example'     => '/',
            '/\\/evil.example'      => '/',
            '/a/\\/evil.example'    => '/',   // not just at position 1
            "/\r\n/evil.example"    => '/',   // CRLF: the ordering bug
            "/\n/evil.example"      => '/',
            "/\t/evil.example"      => '/',   // a browser strips the tab
            "/\r//evil.example"     => '/',
            "/x\0y"                 => '/',
            '/ /evil.example'       => '/',   // a space is stripped in transit too
            'https://evil.example'  => '/',   // a scheme at the front
            'HTTPS://EVIL.EXAMPLE'  => '/',
            '\\\\evil.example'      => '/',   // a UNC path is not a URL path
            '/\\'                   => '/',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                Response::redirect((string) $input)->headers()['Location'],
                'redirect(' . self::readable((string) $input) . ')'
            );
        }
    }

    /**
     * The same rule, one layer down, on the exact string Http\Kernel builds for
     * the canonical 301: basePath . normalised path . query.
     *
     * Domain\Seo\Url::clean() keeps "\evil.example" as a single path segment, so
     * the value that reaches here from a request for `/%5Cevil.example/` really
     * is "/\evil.example". That is the live-application evidence in the report,
     * reproduced without a web server.
     */
    public function testTheCanonicalRedirectCannotBeAimedOffSite(): void
    {
        $this->assertSame('/', Response::sameOriginPath('/\\evil.example'));
        $this->assertSame('/', Response::sameOriginPath('/\\127.0.0.2'));

        // A subdirectory install's basePath is prepended by the Kernel; it is
        // still just a path, and still refused when the rest is hostile.
        $this->assertSame('/php/public/faq', Response::sameOriginPath('/php/public/faq'));
        $this->assertSame('/', Response::sameOriginPath('/php/public/\\evil.example'));
    }

    /**
     * A Location is a header, and a header with no ceiling is a footgun. The
     * longest thing this site can build is a canonical plus a query string,
     * both already capped at 1000 by Http\Kernel.
     */
    public function testAnAbsurdlyLongLocationIsRefusedRatherThanSent(): void
    {
        $this->assertSame('/' . str_repeat('a', 1999), Response::sameOriginPath('/' . str_repeat('a', 1999)));
        $this->assertSame('/', Response::sameOriginPath('/' . str_repeat('a', 2000)));
    }

    /**
     * Non-ASCII bytes are NOT an authority and must survive: they are what a
     * path with an Urdu segment is made of, and refusing them would turn a
     * canonical redirect into a bounce to the home page.
     */
    public function testAPathWithNonAsciiBytesIsStillAPath(): void
    {
        $this->assertSame('/کراچی', Response::sameOriginPath('/کراچی'));
    }

    /**
     * A header value may not carry a newline. Without this, one caller-supplied
     * value becomes as many extra headers as the attacker likes.
     */
    public function testAHeaderValueCannotSmuggleMoreHeaders(): void
    {
        $response = Response::text('x')->header('X-Thing', "value\r\nX-Injected: yes");

        $this->assertSame('valueX-Injected: yes', $response->headers()['X-Thing'], 'the newline is gone, so there is one header');
        $this->assertStringNotContains("\n", $response->headers()['X-Thing']);
        $this->assertStringNotContains("\r", $response->headers()['X-Thing']);
    }

    public function testHasHeaderDoesNotCareAboutCase(): void
    {
        $response = Response::text('x')->header('Content-Length', '1');

        $this->assertTrue($response->hasHeader('content-length'));
        $this->assertTrue($response->hasHeader('CONTENT-LENGTH'));
        $this->assertFalse($response->hasHeader('Content-Range'));
    }

    public function testNoStoreAndCacheForSayOppositeThings(): void
    {
        $this->assertStringContains('no-store', Response::html('x')->noStore()->headers()['Cache-Control']);
        $this->assertSame('public, max-age=600', Response::html('x')->cacheFor(600)->headers()['Cache-Control']);

        // A negative age is a caller's arithmetic going wrong, not permission to
        // send max-age=-1.
        $this->assertSame('public, max-age=0', Response::html('x')->cacheFor(-5)->headers()['Cache-Control']);
    }

    public function testAnEmptyResponseHasNoBodyAndNoContentType(): void
    {
        $response = Response::empty();

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body());
        $this->assertSame([], $response->headers());
    }

    // ------------------------------------------------------ the status line

    /**
     * THE 419-BECOMES-500 BUG, AS A UNIT.
     *
     * Measured on the staging server (Apache 2.4.68, mod_php 8.2.34): a script
     * that called `http_response_code(419)` and nothing else was answered
     * `HTTP/1.1 500 Internal Server Error` on the wire, with its own headers and
     * its own body behind it. 400, 403, 422 and 451 all arrived as themselves;
     * 498 also became 500. The cause is Apache's `ap_index_of_response()`, whose
     * fallback for a status its table does not carry is the 500 slot - and
     * `http_response_code()` gives the server an integer and no reason phrase to
     * use instead.
     *
     * send() cannot be watched from here: `header()` does nothing under CLI and
     * `headers_list()` comes back empty. So the decision send() makes is a pure
     * function, and this is the test of it; the end-to-end proof is over real
     * Apache and is quoted in docs and in `Core\Response::statusLine()`.
     */
    public function testAnUnregisteredStatusIsGivenAStatusLineOfItsOwn(): void
    {
        // Registered: the server has a phrase, http_response_code() is enough,
        // and writing a line by hand would only risk disagreeing with it.
        foreach ([200, 204, 301, 302, 303, 400, 401, 403, 404, 405, 409, 413, 422, 429, 500, 501, 503] as $status) {
            $this->assertTrue(Response::isTransportable($status), $status . ' is a registered status');
            $this->assertSame(null, Response::statusLine($status), $status . ' needs no hand-written line');
        }

        // Not registered. 419 is Laravel's invention, 498 is nobody's, 418 is a
        // joke Apache's table does not carry.
        foreach ([418, 419, 440, 498, 599] as $status) {
            $this->assertFalse(Response::isTransportable($status), $status . ' is not a status a server knows');
        }

        $this->assertSame('HTTP/1.1 419 Client Error', Response::statusLine(419));
        $this->assertSame('HTTP/1.1 599 Server Error', Response::statusLine(599));

        // The phrase is the code's CLASS, never an invented name: an
        // unregistered code does not have one, and guessing would be this file
        // deciding what the internet calls something.
        $this->assertSame('HTTP/1.1 418 Client Error', Response::statusLine(418));
    }

    /**
     * The line is a line a server will accept: one CRLF-free token sequence
     * starting with the protocol, exactly as `header()` requires in order to
     * treat it as a status rather than as a field.
     */
    public function testTheHandWrittenStatusLineIsShapedLikeAStatusLine(): void
    {
        foreach ([419, 498, 599, 199] as $status) {
            $line = Response::statusLine($status);

            $this->assertTrue(is_string($line), $status . ' produced no line');
            $this->assertSame(
                1,
                preg_match('#^HTTP/1\.1 [1-5][0-9]{2} [A-Za-z][A-Za-z ]{2,30}$#', (string) $line),
                'not a status line: ' . var_export($line, true)
            );

            // mod_php copies the line from offset 9 and only when it is longer
            // than twelve characters; a shorter one is silently ignored and the
            // whole fix would be a no-op.
            $this->assertTrue(strlen((string) $line) > 12, 'too short for mod_php to copy: ' . $line);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A redirect input in a failure message, with its control characters
     * visible. Without this, "redirect(/
     * /evil.example)" is printed across two lines and reads as a broken runner.
     */
    private static function readable(string $input): string
    {
        return '"' . str_replace(["\r", "\n", "\t", "\0"], ['\\r', '\\n', '\\t', '\\0'], $input) . '"';
    }

    /**
     * Run tests/Core/response-child.php and decode the line it writes.
     *
     * @param  list<string> $ini extra arguments for the child's php, e.g. -d flags
     * @return array{zlib:string,handlers:list<string>,survives:bool,bytes:int,chars:int,headers:array<string,string>,headHeaders:array<string,string>}
     */
    private function childReport(array $ini, string $case = 'plain'): array
    {
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so the compression flag cannot be set for real');
        }

        $child = Paths::root() . '/tests/Core/response-child.php';
        if (!is_file($child)) {
            $this->skip('tests/Core/response-child.php is missing');
        }

        $pipes   = [];
        $process = proc_open(
            array_merge([PHP_BINARY], $ini, [$child]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Paths::root(),
            ['SPEEDAT_RESPONSE_CASE' => $case] + $this->inheritedEnvironment()
        );

        if (!is_resource($process)) {
            $this->fail('could not start ' . $child);
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            $this->fail('the child exited ' . $code . ': ' . trim($err . ' ' . $out));
        }

        // The report is the LAST line: a deprecation notice on a future PHP
        // would otherwise be decoded instead of it.
        $lines  = array_values(array_filter(array_map('trim', explode("\n", $err)), static fn (string $l): bool => $l !== ''));
        $report = json_decode((string) end($lines), true);

        if (!is_array($report)) {
            $this->fail('the child said something that is not JSON: ' . trim($err));
        }

        /** @var array{zlib:string,handlers:list<string>,survives:bool,bytes:int,chars:int,headers:array<string,string>,headHeaders:array<string,string>} $report */
        return $report;
    }

    /**
     * proc_open()'s env array REPLACES the environment, and a PHP child on
     * Windows with no SystemRoot does not start.
     *
     * getenv() rather than $_SERVER, because $_SERVER is a variable an earlier
     * test may have replaced - see the note in BootstrapCliTest, where copying a
     * doctored $_SERVER into a child made this style of helper pass alone and
     * fail in the full run.
     *
     * @return array<string,string>
     */
    private function inheritedEnvironment(): array
    {
        $environment = [];

        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }
}
