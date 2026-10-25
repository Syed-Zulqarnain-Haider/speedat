<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * A generated file with the content type it has to be served as: the sitemap
 * and robots.txt.
 *
 * It is deliberately NOT a `Core\Response`. This namespace builds documents;
 * `app/routes.php` - which belongs to the routing phase - decides the status
 * code, the caching headers and how the body reaches the browser. Keeping the
 * two apart is what lets the tests assert the exact bytes of a sitemap without
 * standing up a request.
 */
final class TextDocument
{
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
    ) {
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
