<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\App;
use Speedat\Core\Response;

/**
 * One answered request: the response, and the container that built it.
 *
 * The container is here because two things a test has to see are queued on it
 * rather than written into the response - the cookies (`Core\Cookies` writes
 * them at send time, and a synthetic request is never sent) and the per-request
 * CSP nonce.
 */
final class Answer
{
    public function __construct(
        public readonly Response $response,
        public readonly App $app,
    ) {
    }

    public function body(): string
    {
        return $this->response->body();
    }

    /** A response header as it would be sent, or "" when it was not set. */
    public function header(string $name): string
    {
        foreach ($this->response->headers() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    /** The value queued for a cookie this request set, or null. */
    public function cookie(string $name): ?string
    {
        return $this->app->cookies->queuedValue($name);
    }

    /** The body decoded as JSON. @return array<array-key,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
