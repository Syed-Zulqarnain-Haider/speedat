<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * A small routing table. Patterns are literal paths with `{name}` segments:
 *
 *     $router->get('/quote/{dest}', $handler);
 *     $router->post('/admin/rates/{id}/publish', $handler);
 *
 * A `{name}` matches one path segment and never a slash, so a route cannot be
 * tricked into matching further than it was written to.
 *
 * THE PATH IS DECODED EXACTLY ONCE, in Request::fromGlobals(), before it ever
 * reaches this class. Nothing here decodes anything - see match().
 *
 * A path that matches a pattern under a different method gets 405 with an
 * Allow header rather than 404 - that distinction is what makes a broken form
 * method obvious instead of mysterious.
 */
final class Router
{
    /** @var array<string,list<array{regex:string,params:list<string>,handler:callable}>> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    /**
     * Register a route.
     *
     * The pattern is split into literal pieces and `{name}` pieces FIRST, and
     * each literal piece is quoted on its own afterwards. The obvious order -
     * quote the whole pattern, then look for the braces - does not work, and
     * did not: preg_quote() escapes `{` and `}`, so what the substitution was
     * handed read `\{dest\}` and never matched. The route compiled, looked
     * right, and matched exactly one URL: the literal string "/quote/{dest}".
     * Nothing noticed because phase 1 registered no parameterised route.
     * tests/Core/RouterTest.php does now.
     *
     * @param list<string> $params filled in below, in the order they appear
     */
    public function add(string $method, string $pattern, callable $handler): self
    {
        $params = [];
        $regex  = '';

        $pieces = preg_split(
            '/(\{[a-z_][a-z0-9_]*\})/i',
            $this->normalise($pattern),
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [];

        foreach ($pieces as $piece) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $piece, $m) === 1) {
                $params[] = $m[1];
                // One segment and never a slash, so a route cannot be talked
                // into matching further than it was written to.
                $regex .= '([^/]+)';
                continue;
            }

            $regex .= preg_quote($piece, '#');
        }

        $this->routes[strtoupper($method)][] = [
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Find the handler for a request.
     *
     * PARAMETERS ARE NOT DECODED HERE, and that is the whole point.
     * Request::fromGlobals() rawurldecodes the path once, before the pattern is
     * matched, so by the time a capture group holds a value the percent-escapes
     * are already gone. Decoding it a second time was a hole, not a
     * convenience: `/track/%252e%252e%252fsecret` arrives here as
     * `/track/%2e%2e%2fsecret`, `[^/]+` happily captures `%2e%2e%2fsecret`
     * because it contains no slash, and a second rawurldecode() turned that into
     * `../secret` - a path separator and a traversal inside a value the regex
     * was written to make slash-free. The route that would have been handed it
     * has not been written yet; it would have inherited the hole.
     *
     * Single-encoded input needs nothing from us either: `/quote/a%2Fb` is
     * already `/quote/a/b` when it gets here and fails to match, which is the
     * correct answer for a one-segment placeholder.
     *
     * tests/Core/RouterTest.php builds its requests through the real
     * Request::fromGlobals() pipeline for exactly this reason - a test that
     * hand-builds a Request skips the first decode and would pass either way.
     *
     * @throws HttpError 404 when nothing matches, 405 when only the method is wrong.
     * @return array{handler:callable,params:array<string,string>}
     */
    public function match(Request $request): array
    {
        $path   = $this->normalise($request->path);
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $m[$i + 1] ?? '';
                }

                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        $allowed = [];
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $otherMethod;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            throw HttpError::methodNotAllowed(['path' => $path, 'allow' => implode(', ', array_unique($allowed))]);
        }

        throw HttpError::notFound(['path' => $path]);
    }

    /** Trailing slashes are noise: /about/ and /about are the same page. */
    private function normalise(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
