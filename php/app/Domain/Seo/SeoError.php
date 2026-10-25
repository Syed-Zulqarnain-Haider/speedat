<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * Something the SEO layer was asked to build and refuses to build wrong.
 *
 * There is exactly one situation that raises this: a base URL that is not an
 * absolute http(s) URL. Everything else in here degrades - a missing content
 * field falls back, an unparseable opening-hours line is omitted - because a
 * page that renders without a `description` is better than a page that 500s.
 * A wrong base URL is different: it would put the wrong host into every
 * canonical, every sitemap entry and every share card, and a wrong canonical
 * is the one SEO mistake that is invisible and expensive.
 */
final class SeoError extends \RuntimeException
{
}
