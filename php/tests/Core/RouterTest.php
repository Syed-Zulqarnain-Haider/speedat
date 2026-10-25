<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\HttpError;
use Speedat\Core\Request;
use Speedat\Core\Response;
use Speedat\Core\Router;
use Speedat\Tests\TestCase;

/**
 * The routing table: what matches, what does not, and the difference between
 * "there is no such page" and "not like that there is not".
 *
 * EVERY REQUEST IN HERE IS BUILT THROUGH Request::fromGlobals(), from a real
 * $_SERVER, and not through Request::synthetic(). That is not fussiness. The
 * path is percent-decoded once, in fromGlobals(); synthetic() takes the path
 * verbatim and skips that step. So a decoding test written against synthetic()
 * measures a pipeline that has one decode in it, while the live site has two -
 * and for a while the live site really did have two, with
 * `/track/%252e%252e%252fsecret` arriving at a handler as `../secret`. Both
 * tests passed throughout. A test that cannot see the bug it is named after is
 * worse than no test, because it stops anybody looking.
 *
 * The 405 case is the other one worth a test of its own. A form that posts to a
 * GET route is a mistake somebody makes every week, and a 404 sends them
 * looking for a missing route that is right there in routes.php.
 */
final class RouterTest extends TestCase
{
    /** @var array<string,mixed> $_SERVER as it was before the test touched it. */
    private array $savedServer = [];

    public function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    public function tearDown(): void
    {
        $_SERVER = $this->savedServer;
    }

    public function testALiteralPathMatchesItsHandler(): void
    {
        $router = new Router();
        $router->get('/about', $this->handler('about'));
        $router->get('/', $this->handler('home'));

        $this->assertSame('home', $this->run($router, 'GET', '/'));
        $this->assertSame('about', $this->run($router, 'GET', '/about'));
    }

    public function testPlaceholdersBecomeParameters(): void
    {
        $router = new Router();
        $router->get('/quote/{dest}', $this->handler('quote'));
        $router->get('/track/{id}/event/{event}', $this->handler('event'));

        $match = $router->match($this->request('GET', '/quote/united-kingdom'));
        $this->assertSame(['dest' => 'united-kingdom'], $match['params']);

        $match = $router->match($this->request('GET', '/track/SP-1/event/7'));
        $this->assertSame(['id' => 'SP-1', 'event' => '7'], $match['params']);
    }

    public function testAPlaceholderNeverEatsASlash(): void
    {
        $router = new Router();
        $router->get('/quote/{dest}', $this->handler('quote'));

        // "/quote/a/b" must not arrive as dest="a/b": a route cannot be
        // tricked into matching further than it was written to.
        $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/quote/a/b'))
        );

        // And the encoded spelling of the same URL. %2F is decoded to a real
        // slash by fromGlobals(), so this is the case above wearing a hat: it
        // must 404 rather than match with dest="a/b".
        $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/quote/a%2Fb')),
            'an encoded slash is still a slash by the time the pattern sees it'
        );
    }

    public function testAnEncodedParameterIsDecodedOnceAndOnlyOnce(): void
    {
        $router = new Router();
        $router->get('/quote/{dest}', $this->handler('quote'));

        // Once: the request line carries UTF-8 percent-escapes and the handler
        // gets the characters.
        $match = $router->match($this->request('GET', '/quote/c%C3%B4te%20d%27ivoire'));
        $this->assertSame("côte d'ivoire", $match['params']['dest']);

        // And only once. %252F is an ENCODED PERCENT followed by "2F" - the
        // customer's destination really is called "a%2Fb" - so one decode gives
        // "a%2Fb" and the parameter must keep it. A second decode would read
        // that as an encoded slash and hand the handler "a/b".
        $match = $router->match($this->request('GET', '/quote/a%252Fb'));
        $this->assertSame('a%2Fb', $match['params']['dest']);
    }

    /**
     * The traversal the second decode allowed, dead.
     *
     * `%252e%252e%252f` is the double-encoded spelling of `../`. One decode
     * leaves `%2e%2e%2f`, which contains no slash, so `[^/]+` captures the lot
     * and the parameter looks harmless. Decode it again and the handler is
     * holding `../secret` - and a handler that trusts a single-segment
     * parameter enough to use it in a filename, a cache key or a redirect has
     * just been handed a path separator.
     */
    public function testADoubleEncodedTraversalStaysInertInAParameter(): void
    {
        $router = new Router();
        $router->get('/track/{id}', $this->handler('track'));

        $match = $router->match($this->request('GET', '/track/%252e%252e%252fsecret'));
        $id    = $match['params']['id'];

        $this->assertSame('%2e%2e%2fsecret', $id, 'decoded once, so the escapes are still escapes');
        $this->assertStringNotContains('/', $id, 'no path separator reached the parameter');
        $this->assertStringNotContains('..', $id, 'and no traversal either');

        // What the removed second decode produced, stated once so the next
        // person can see the size of it without having to put it back. If
        // Router::match() ever decodes again, the assertions above fail and this
        // line says what they will be failing with.
        $this->assertSame('../secret', rawurldecode($id), 'one more decode is a traversal - hence none');

        // The same shape one level of encoding down, for completeness: a single
        // encoded "../" is a real slash after the one decode, so the route does
        // not match at all.
        $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/track/%2e%2e%2fsecret'))
        );
    }

    public function testTrailingSlashesAreNoise(): void
    {
        $router = new Router();
        $router->get('/about', $this->handler('about'));

        $this->assertSame('about', $this->run($router, 'GET', '/about'));
        $this->assertSame('about', $this->run($router, 'GET', '/about/'));

        // A request target with no leading slash is not something a browser
        // sends, but fromGlobals() puts one back and normalise() agrees, so the
        // two halves of that decision are pinned together here.
        $this->assertSame('about', $this->run($router, 'GET', 'about'));
    }

    public function testHeadIsRoutedAsGet(): void
    {
        $router = new Router();
        $router->get('/', $this->handler('home'));

        $this->assertSame('home', $this->run($router, 'HEAD', '/'));
    }

    public function testAQueryStringIsNotPartOfThePath(): void
    {
        $router = new Router();
        $router->get('/quote/{dest}', $this->handler('quote'));

        $match = $router->match($this->request('GET', '/quote/oman?weight=500&ref=x'));
        $this->assertSame(['dest' => 'oman'], $match['params']);
    }

    public function testNothingMatchingIsA404(): void
    {
        $router = new Router();
        $router->get('/', $this->handler('home'));

        $error = $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/no-such-page'))
        );

        $this->assertSame(404, $error->status);
        $this->assertSame('/no-such-page', $error->detail['path'] ?? null);
        $this->assertStringNotContains('no-such-page', $error->getMessage(), 'the public message echoes nothing back');
    }

    public function testTheWrongMethodIsA405WithAnAllowHeader(): void
    {
        $router = new Router();
        $router->post('/quote', $this->handler('quote'));
        $router->add('PUT', '/quote', $this->handler('put'));

        $error = $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/quote'))
        );

        $this->assertSame(405, $error->status);

        $allow = (string) ($error->detail['allow'] ?? '');
        $this->assertStringContains('POST', $allow);
        $this->assertStringContains('PUT', $allow);
    }

    public function testAnEmptyRouterIs404AndNot405(): void
    {
        $error = $this->assertThrows(
            HttpError::class,
            fn () => (new Router())->match($this->request('GET', '/'))
        );

        $this->assertSame(404, $error->status);
    }

    public function testTheFirstMatchingRouteWins(): void
    {
        $router = new Router();
        $router->get('/quote/{dest}', $this->handler('parameter'));
        $router->get('/quote/special', $this->handler('literal'));

        // Registration order decides, and this test exists so that changing it
        // is a deliberate act rather than a surprise.
        $this->assertSame('parameter', $this->run($router, 'GET', '/quote/special'));
    }

    public function testAPatternIsMatchedLiterallyAndNotAsARegularExpression(): void
    {
        $router = new Router();
        $router->get('/a.b', $this->handler('dotted'));

        $this->assertSame('dotted', $this->run($router, 'GET', '/a.b'));
        $this->assertThrows(
            HttpError::class,
            fn () => $router->match($this->request('GET', '/axb')),
            'the dot is a dot, not "any character"'
        );
    }

    public function testMethodNamesAreNotCaseSensitiveWhenRegistering(): void
    {
        $router = new Router();
        $router->add('post', '/quote', $this->handler('quote'));

        $this->assertSame('quote', $this->run($router, 'POST', '/quote'));
    }

    // ---------------------------------------------------------------- helpers

    private function handler(string $name): \Closure
    {
        return static fn (): Response => Response::text($name);
    }

    /**
     * A Request built the way the web server builds one.
     *
     * $requestUri is the RAW request target, percent-escapes and query string
     * included, exactly as it appears on the request line - so the one decode
     * the live pipeline performs happens here too, in the code that performs it
     * in production, rather than being assumed.
     */
    private function request(string $method, string $requestUri): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $requestUri,
            'SCRIPT_NAME'    => '/index.php',
            'REMOTE_ADDR'    => '127.0.0.1',
            'HTTP_HOST'      => 'speedat.test',
        ];

        return Request::fromGlobals();
    }

    private function run(Router $router, string $method, string $path): string
    {
        $match   = $router->match($this->request($method, $path));
        $handler = $match['handler'];

        /** @var Response $response */
        $response = $handler(null, $match['params']);

        return $response->body();
    }
}
