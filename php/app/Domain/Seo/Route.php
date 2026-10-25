<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * One public page, as the SEO layer sees it.
 *
 * The `title` is the leaf only - "Services", "Questions" - exactly as the
 * Next.js pages set it (`export const metadata = { title: "Services" }`);
 * `Meta` appends the company name the way the Next.js title template did.
 *
 * `changeFrequency` and `priority` are sitemap hints and nothing else. They
 * are carried here rather than computed so that "what does the sitemap say
 * about this page" has a written answer in one file.
 */
final class Route
{
    public function __construct(
        /** Stable key the pages and the tests address a route by: home, services, about, contact, faq. */
        public readonly string $key,
        /** Canonical path, no trailing slash except the root. */
        public readonly string $path,
        /** The leaf title, before the company name is appended. */
        public readonly string $title,
        /** Label in the breadcrumb trail; empty on the home page, which is the trail's root. */
        public readonly string $breadcrumb = '',
        public readonly string $changeFrequency = 'monthly',
        public readonly float $priority = 0.6,
        /** False keeps a page out of the sitemap and gives it `noindex`. */
        public readonly bool $indexable = true,
    ) {
    }

    public function isHome(): bool
    {
        return $this->path === '/';
    }
}
