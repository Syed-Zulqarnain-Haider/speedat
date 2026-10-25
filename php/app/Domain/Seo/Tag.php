<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * One tag in the head, as data rather than as markup.
 *
 * The SEO classes never build HTML. They build these, and
 * `app/Views/partials/seo.php` prints them with `e()` on every name and every
 * value. That is the whole reason this class exists: a class that returned
 * `'<meta name="description" content="' . $x . '">'` would be one refactor away
 * from printing an owner-typed `"` into the head, and a template that prints
 * `$tag->html()` raw is a template nobody can review by looking at it.
 */
final class Tag
{
    /**
     * @param 'meta'|'link'         $element
     * @param array<string,string>  $attributes in the order they should print
     */
    private function __construct(
        public readonly string $element,
        public readonly array $attributes,
    ) {
    }

    /** `<meta name="..." content="...">` - description, robots, twitter:*. */
    public static function meta(string $name, string $content): self
    {
        return new self('meta', ['name' => $name, 'content' => $content]);
    }

    /** `<meta property="..." content="...">` - the Open Graph tags, which use RDFa's property, not name. */
    public static function property(string $property, string $content): self
    {
        return new self('meta', ['property' => $property, 'content' => $content]);
    }

    /** `<link rel="..." href="...">` - canonical. */
    public static function link(string $rel, string $href): self
    {
        return new self('link', ['rel' => $rel, 'href' => $href]);
    }
}
