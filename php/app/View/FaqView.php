<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Engine;

/**
 * /faq. Port of `src/app/(site)/faq/page.tsx`.
 *
 * `content.faq` (`Question? | Answer` per line) as one list with every answer
 * OPEN - nothing to tap to read, nothing that folds - a hairline after each
 * pair, then the contact line. docs/assets.md says the same: "/faq is a
 * `<dl class="faq-list">` with every answer open", and ui.js has no code for it.
 *
 * `items` is also what the FAQPage structured data must be generated from, so
 * the JSON-LD and what a visitor reads can never differ. The SEO track reads
 * it through `items()`.
 */
final class FaqView
{
    /** @param list<array{q:string,a:string}> $items */
    private function __construct(
        public readonly PageView $page,
        public readonly Doc $doc,
        public readonly string $lede,
        public readonly array $items,
        public readonly Reach $reach,
    ) {
    }

    public static function build(PageView $page): self
    {
        $doc   = $page->doc;
        $items = [];

        foreach (Engine::lines($doc->content()->faq) as $line) {
            $p = Engine::parts($line, 2);
            if ($p[0] === '' && $p[1] === '') {
                continue;
            }
            $items[] = ['q' => $p[0], 'a' => $p[1]];
        }

        return new self(
            page: $page,
            doc: $doc,
            lede: $doc->content()->faqLede,
            items: $items,
            reach: Reach::build($doc),
        );
    }
}
