<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\App;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Router;
use Speedat\Core\Session;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Http\Kernel;
use Speedat\Tests\TestCase;

/**
 * The base for every routing test: one synthetic request all the way through
 * the real routing table, the real controllers and the real templates.
 *
 * NOTHING IS STUBBED. `app/routes.php` is the file the front controller loads,
 * `Http\Kernel::handle()` is the method it calls, and the response that comes
 * back is the one it would send. The bugs worth catching at this level - a
 * missing status, a content type, a guard that was never wired, a page that
 * throws - all live in the wiring, and a test with a fake router in it cannot
 * see any of them.
 *
 * WHAT IS NOT OBSERVABLE HERE. `Core\App::send()` is what adds the security
 * headers and suppresses a HEAD body, and it can only be watched by a real
 * server (`header()` does nothing under CLI and `headers_list()` comes back
 * empty). So this suite asserts status, content type, caching, `Location`,
 * `Allow`, `Retry-After` and the body; the CSP and its friends are checked over
 * HTTP against the preview server.
 *
 * THE JSON BODY. `Core\Request::json()` reads `php://input`, which under CLI is
 * standard input - so a JSON route cannot be exercised without giving the
 * process something to read. `postJson()` swaps the `php://` wrapper for one
 * that serves the body, for exactly the length of the request, and restores it
 * afterwards. The alternative was a child process per case; this keeps the
 * failure in the same stack trace as the assertion.
 */
abstract class HttpTestCase extends TestCase
{
    /** The cookie the anonymous CSRF secret lives in; a POST has to carry it back. */
    protected const CSRF_COOKIE = Session::ANON_COOKIE;

    public function setUp(): void
    {
        // Every request must read the document for itself: the per-request memo
        // would otherwise carry one test's hold state into the next.
        Live::forget();
    }

    public function tearDown(): void
    {
        Live::forget();
    }

    /**
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers lower-cased names
     * @param array<string,string> $cookies
     */
    protected function get(string $path, array $query = [], array $headers = [], array $cookies = []): Answer
    {
        return $this->send(Request::synthetic('GET', $path, [], $query, $headers, $cookies));
    }

    /**
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    protected function post(string $path, array $post = [], array $headers = [], array $cookies = []): Answer
    {
        return $this->send(Request::synthetic('POST', $path, $post, [], $headers, $cookies));
    }

    /**
     * A POST whose body is raw JSON on php://input, the way the browser's
     * `fetch()` sends it.
     *
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    protected function postJson(string $path, string $body, array $headers = [], array $cookies = []): Answer
    {
        $headers += ['content-type' => 'application/json', 'content-length' => (string) strlen($body)];

        InputStream::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', InputStream::class);

        try {
            return $this->send(Request::synthetic('POST', $path, [], [], $headers, $cookies));
        } finally {
            // Restored whatever happened: leaving a fake php:// wrapper
            // registered would break every test after this one, in ways that
            // look like anything but this line.
            stream_wrapper_restore('php');
            InputStream::$body = '';
        }
    }

    /** One request through the real table. */
    protected function send(Request $request): Answer
    {
        Live::forget();

        $router = new Router();

        /** @var \Closure(Router):void $routes */
        $routes = require Paths::root() . '/app/routes.php';
        $routes($router);

        $app = new App($request);

        return new Answer(Kernel::handle($app, $router), $app);
    }

    /* ---------------------------------------------------------- assertions */

    protected function assertStatus(int $expected, Answer $answer, string $where = ''): void
    {
        $this->assertSame($expected, $answer->response->status, ($where !== '' ? $where . ': ' : '') . 'status');
    }

    protected function assertContentType(string $expected, Answer $answer, string $where = ''): void
    {
        $this->assertSame($expected, $answer->header('Content-Type'), ($where !== '' ? $where . ': ' : '') . 'content type');
    }

    /** Exactly one h1 per page - the quality bar's rule, checked rather than reviewed. */
    protected function assertOneH1(Answer $answer, string $where = ''): void
    {
        $this->assertSame(1, substr_count($answer->body(), '<h1'), ($where !== '' ? $where . ': ' : '') . 'expected exactly one <h1>');
    }

    /** The head every page must carry, and never twice. */
    protected function assertHeadIsWhole(Answer $answer, string $where = ''): void
    {
        $html  = $answer->body();
        $label = $where !== '' ? $where . ': ' : '';

        $this->assertSame(1, substr_count($html, '<title>'), $label . 'one title, never two');
        $this->assertSame(1, substr_count($html, 'application/ld+json'), $label . 'one JSON-LD graph');
        $this->assertStringContains('<meta name="description" content="', $html, $label . 'description');
        $this->assertStringContains('property="og:title"', $html, $label . 'og:title');
        $this->assertStringContains('name="twitter:card" content="summary_large_image"', $html, $label . 'twitter card');
    }

    /**
     * The JSON-LD graph, decoded. Fails the test when it is not valid JSON.
     *
     * @return array<array-key,mixed>
     */
    protected function jsonLd(Answer $answer): array
    {
        if (preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $answer->body(), $m) !== 1) {
            $this->assertTrue(false, 'the page printed no JSON-LD block');
        }

        $decoded = json_decode($m[1] ?? '', true);
        $this->assertTrue(is_array($decoded), 'the JSON-LD block did not parse as JSON');

        /** @var array<array-key,mixed> $decoded */
        return $decoded;
    }

    /** The one sentence a rejected contact form prints above the fields. */
    protected function formNotice(Answer $answer): string
    {
        if (preg_match('#<div class="notice err" role="alert">(.*?)</div>#s', $answer->body(), $m) !== 1) {
            return '';
        }

        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Every per-field error message on the page, in document order. @return list<string> */
    protected function fieldErrors(Answer $answer): array
    {
        preg_match_all('#<span class="contact-form-err">(.*?)</span>#s', $answer->body(), $m);

        return array_map(
            static fn (string $s): string => html_entity_decode(trim($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $m[1],
        );
    }

    /**
     * GET /contact and take the guards off it, as a browser would: the CSRF
     * token, the render token, and the cookie the anonymous CSRF secret lives
     * in.
     *
     * @return array{token:string,form:string,cookies:array<string,string>}
     */
    protected function contactGuards(): array
    {
        $answer = $this->get('/contact');

        preg_match('/name="_token" value="([^"]*)"/', $answer->body(), $token);
        preg_match('/name="t" value="([^"]*)"/', $answer->body(), $form);

        $secret = $answer->app->cookies->queuedValue(self::CSRF_COOKIE);

        return [
            'token'   => $token[1] ?? '',
            'form'    => $form[1] ?? '',
            'cookies' => $secret === null ? [] : [self::CSRF_COOKIE => $secret],
        ];
    }
}
