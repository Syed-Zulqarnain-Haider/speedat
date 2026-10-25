<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Core\Str;
use Speedat\Domain\Pricing\Engine;

/**
 * Title, description, canonical, robots and the share tags, for one page.
 *
 * WHAT IS DECIDED HERE, AND WHY
 *
 * - THE TITLE is the page's leaf plus the company name, the way the Next.js
 *   title template did it (`%s — Speedat International Courier`), and
 *   deduplicated: a page whose own title already contains the company name -
 *   "About Speedat International Courier" - is left alone rather than saying
 *   it twice. Blank company, blank leaf: whichever exists is the title.
 *
 * - THE DESCRIPTION comes from the content field the page itself renders as
 *   its lede, so editing the visible sentence edits the search result. When
 *   that field is blank the site-wide generated sentence is used - the same
 *   one the Next.js root layout generated. No page ever invents a description
 *   of itself. It is clamped at 160 characters on a word boundary, because a
 *   description Google truncates mid-word is a description the owner did not
 *   write.
 *
 * - THE CANONICAL is `Url::absolute()`: one URL per page, no query, no
 *   trailing slash. It is emitted on every indexable page including the home
 *   page, and it is the same string that goes into `og:url` and the sitemap,
 *   because three places disagreeing about a page's address is exactly how a
 *   site ends up indexed twice. A page carrying `noindex` - an error page,
 *   anything under /admin - emits neither, because a canonical is itself an
 *   indexing instruction and two contradictory ones is worse than one.
 *
 * - ROBOTS says `index, follow` for the five public pages and
 *   `noindex, nofollow` for anything marked unindexable. `max-image-preview`,
 *   `max-snippet` and `max-video-preview` are set to the permissive values so
 *   a rich result is allowed to show the share card.
 *
 * - THE SHARE TAGS carry the image with its absolute URL, MIME type and pixel
 *   size, which is what WhatsApp needs to draw a card rather than a bare link.
 *   WhatsApp is this business's booking channel, so this matters more here
 *   than the SEO value of it (docs/quality-bar.md §1).
 */
final class Meta
{
    /** Google renders roughly this much of a description; past it the owner's sentence is cut by a machine. */
    public const DESCRIPTION_LIMIT = 160;

    public function __construct(
        private readonly SiteFacts $facts,
        private readonly Url $url,
        private readonly ShareCard $card,
    ) {
    }

    /** `<title>` for a route: the leaf, the em dash, the company name - once. */
    public function title(Route $route): string
    {
        $leaf    = Str::squish($route->title);
        $company = Str::squish($this->facts->companyName());

        if ($company === '') {
            return $leaf;
        }
        if ($leaf === '') {
            return $company;
        }

        // Already names the company ("About Speedat International Courier"):
        // appending it again is the classic duplicated-brand title.
        if (mb_stripos($leaf, $company) !== false) {
            return $leaf;
        }

        return $leaf . ' - ' . $company;
    }

    /**
     * The page's description: its own content field, else the site-wide one.
     *
     * The field per page is the lede the page prints under its h1, so what a
     * searcher reads in the result is what a visitor reads on arrival.
     */
    public function description(Route $route): string
    {
        $content = $this->facts->content();

        $own = match ($route->key) {
            // The home page has no lede; `content.heroSub` is the line under
            // the headline, and when it is blank the generated sentence says
            // the same thing the calculator does.
            'home'     => $content->heroSub,
            'services' => $content->servicesLede,
            'about'    => Engine::lines($content->story)[0] ?? '',
            'contact'  => $content->contactLede,
            // `content.faqLede` is optional and the seed leaves it blank, so
            // without the second step here /faq shipped the SAME site-wide
            // sentence as /, and two pages sharing one description is two
            // pages competing for one result.
            'faq'      => Str::squish($content->faqLede) !== '' ? $content->faqLede : $this->faqSummary(),
            default    => '',
        };

        $text = Str::squish($own);
        if ($text === '') {
            $text = Str::squish($this->facts->siteDescription());
        }

        return self::clamp($text, self::DESCRIPTION_LIMIT);
    }

    /**
     * The FAQ page's sentence when the owner has not written a lede: the
     * QUESTIONS the page prints, in the page's own order, from the same
     * `SiteFacts::faqItems()` parse that feeds the visible list and the
     * `FAQPage` graph. So it cannot drift from what a visitor reads, and it
     * cannot be blank while the page has content.
     *
     * The questions and not the answers, for two reasons: a searcher types a
     * question, and an answer cut at 160 characters is a half-answer, which
     * is worse in a result than no answer at all. Each question carries its
     * own punctuation (the owner writes "How do I book?"), so they are joined
     * with a space and nothing is added to the owner's words.
     */
    private function faqSummary(): string
    {
        $questions = [];

        foreach ($this->facts->faqItems() as $item) {
            $question = Str::squish($item['question']);
            if ($question !== '') {
                $questions[] = $question;
            }
        }

        return implode(' ', $questions);
    }

    /** The one absolute URL this page is to be indexed under. */
    public function canonical(Route $route): string
    {
        return $this->url->absolute($route->path);
    }

    /**
     * Every head tag for this page, in print order.
     *
     * `<title>` is not in here: it is an element with text content, not an
     * attribute-only tag, so the partial prints it from `title()` directly.
     *
     * @return list<Tag>
     */
    public function tags(Route $route): array
    {
        $title       = $this->title($route);
        $description = $this->description($route);
        $canonical   = $this->canonical($route);
        $company     = $this->facts->companyName();

        // A page that is not to be indexed issues no indexing instruction
        // either: a `noindex` and a `canonical` on the same page contradict
        // each other, and a 404 whose canonical names a real URL is how an
        // error page gets indexed under that URL's name. The share tags stay -
        // a link to an error page should still render as this business's card
        // rather than as a bare URL - but without `og:url`, so nothing a
        // visitor typed into the address bar is reflected into the head.
        $indexable = $route->indexable && !Routes::isDisallowed($route->path);

        $tags = [
            Tag::meta('description', $description),
            Tag::meta('robots', $this->robots($route)),
        ];

        if ($indexable) {
            $tags[] = Tag::link('canonical', $canonical);
        }

        $tags[] = Tag::property('og:type', 'website');
        if ($company !== '') {
            $tags[] = Tag::property('og:site_name', $company);
        }
        $tags[] = Tag::property('og:title', $title);
        $tags[] = Tag::property('og:description', $description);
        if ($indexable) {
            $tags[] = Tag::property('og:url', $canonical);
        }
        $tags[] = Tag::property('og:locale', 'en_US');

        // Absolute URL, MIME type and size: without the last two a scraper has
        // to fetch the image before it can decide to draw a card, and some
        // (WhatsApp among them) simply do not.
        $tags[] = Tag::property('og:image', $this->card->absoluteUrl());
        $tags[] = Tag::property('og:image:type', $this->card->mimeType());
        $tags[] = Tag::property('og:image:width', (string) $this->card->width());
        $tags[] = Tag::property('og:image:height', (string) $this->card->height());
        $tags[] = Tag::property('og:image:alt', $this->card->alt());

        $tags[] = Tag::meta('twitter:card', 'summary_large_image');
        $tags[] = Tag::meta('twitter:title', $title);
        $tags[] = Tag::meta('twitter:description', $description);
        $tags[] = Tag::meta('twitter:image', $this->card->absoluteUrl());
        $tags[] = Tag::meta('twitter:image:alt', $this->card->alt());

        return $tags;
    }

    /** The robots directive line for a route. */
    public function robots(Route $route): string
    {
        if (!$route->indexable || Routes::isDisallowed($route->path)) {
            return 'noindex, nofollow';
        }

        return 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    }

    /**
     * Cut a sentence to a length without cutting a word in half, and without
     * leaving a dangling comma or dash where the cut fell.
     *
     * Multibyte-safe: the content is typed by a human and will contain the
     * en dash and the middle dot the site's copy uses.
     */
    public static function clamp(string $text, int $limit): string
    {
        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        // Multibyte-safe: rtrim() with a character list works on BYTES, and the
        // bytes of "·", "–" and "—" also occur inside unrelated multibyte
        // letters - trimming those would cut a character in half and leave
        // invalid UTF-8 in the description.
        return (string) preg_replace('/[\s,;:·\x{2013}\x{2014}\-]+$/u', '', $cut);
    }
}
