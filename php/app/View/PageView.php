<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Seo\Head;

/**
 * Everything the shell needs, on every page: the head, the header, the nav,
 * the theme and the footer. Port of `src/app/(site)/layout.tsx`.
 *
 * Each page's own view model carries one of these, so `site/layout.php` never
 * reaches into a page's data and a page never has to know what the footer
 * prints.
 *
 * THE HEAD IS THE SEO TRACK'S. A controller builds
 * `Head::forRoute($facts, $url, Routes::byKey('faq'))` and passes it as `$seo`;
 * the layout then prints `partials/seo`, which owns the title, every meta and
 * link tag, and the JSON-LD graph. This class only falls back - to a raw
 * `$headHtml` string, and past that to `site/partials/head-fallback` plus the
 * `LocalBusiness` node below - so that a page is never shipped with no title
 * and no share card at all. Exactly one of the three renders.
 *
 * THEME. The site is light for everyone by default. `theme` is read from the
 * "theme" cookie on the server and printed as `data-theme` on `<html>` - that
 * is the whole reason there is no flash of the wrong theme, and it is why the
 * value must never be decided in the browser. ui.js writes the same cookie
 * when the toggle is tapped; the two agree on the name and on the values
 * "light" and "dark".
 */
final class PageView
{
    /** The five page links, in order. "Get a price" is the whole product, so it comes first. */
    private const LINKS = [
        ['href' => '/', 'label' => 'Get a price'],
        ['href' => '/services', 'label' => 'Services'],
        ['href' => '/about', 'label' => 'About'],
        ['href' => '/contact', 'label' => 'Contact'],
        ['href' => '/faq', 'label' => 'FAQ'],
    ];

    public const THEME_LIGHT = 'light';
    public const THEME_DARK  = 'dark';

    /**
     * The colour behind the address bar on phones. It is the colour of the
     * strip the page begins with, and since 2026-09-28 (owner, docs/brand.md)
     * that strip is the yellow header in BOTH themes - the page behind the bar
     * inverts, the bar does not. Painting the phone's chrome white over a
     * yellow header drew a seam across the top of every page; painting it navy
     * in dark mode drew a darker one.
     */
    private const BAR = [self::THEME_LIGHT => '#FFCC00', self::THEME_DARK => '#FFCC00'];

    public function __construct(
        public readonly Doc $doc,
        /** The current path, leading slash, no trailing slash: "/" or "/services". */
        public readonly string $path,
        /** The page's own title, without the company name: "Services". */
        public readonly string $title,
        public readonly string $description = '',
        /** "light" or "dark", from the cookie. Anything else is light. */
        public readonly string $theme = self::THEME_LIGHT,
        /**
         * The head, from `Speedat\Domain\Seo`: the title, every meta and link
         * tag, and the JSON-LD graph. This is the real one - build it with
         * `Head::forRoute(...)` or `Head::forKey(...)` and the layout prints
         * `partials/seo` with it.
         */
        public readonly ?Head $seo = null,
        /**
         * A pre-rendered `<head>` fragment, printed raw. A second fallback, for
         * a caller that has the markup but not a `Head` - a fixture, or a page
         * outside the five routes.
         */
        public readonly string $headHtml = '',
        /** The year the footer prints; injected so a test does not depend on the clock. */
        public readonly int $year = 0,
        /**
         * The deferred scripts, IN ORDER. They are `defer`, so they execute in
         * document order, and calculator.js needs ui.js's helpers and
         * pricing.js's engine to be there already - hence pricing, ui,
         * calculator and not any other arrangement. Every page gets ui.js; only
         * the quote page gets the other two.
         *
         * @var list<string>
         */
        public readonly array $scripts = ['js/ui.js'],
    ) {
    }

    /** The quote page's three, in the one order that works. @var list<string> */
    public const CALCULATOR_SCRIPTS = ['js/pricing.js', 'js/ui.js', 'js/calculator.js'];

    /** Light unless the cookie says exactly "dark". An unknown value is light, never a broken attribute. */
    public static function themeFromCookie(?string $cookie): string
    {
        return $cookie === self::THEME_DARK ? self::THEME_DARK : self::THEME_LIGHT;
    }

    public function themeColor(): string
    {
        return self::BAR[$this->theme] ?? self::BAR[self::THEME_LIGHT];
    }

    /** "Services - Speedat International Courier"; the home page's own title is not doubled. */
    public function documentTitle(): string
    {
        $company = trim($this->doc->company()->name);

        if ($company === '') {
            return $this->title;
        }
        if ($this->title === '' || $this->title === $company) {
            return $company;
        }

        return $this->title . ' - ' . $company;
    }

    /**
     * The five links with the current page marked.
     *
     * @return list<array{href:string,label:string,current:bool}>
     */
    public function navLinks(): array
    {
        $out = [];
        foreach (self::LINKS as $link) {
            $out[] = [
                'href'    => $link['href'],
                'label'   => $link['label'],
                'current' => $link['href'] === $this->path,
            ];
        }

        return $out;
    }

    /** The first word of the company name, which is the phone header's brand mark. */
    public function brandFirst(): string
    {
        $words = preg_split('/\s+/', trim($this->doc->company()->name)) ?: [];

        return $words[0] ?? $this->doc->company()->name;
    }

    /** The rest of the name, which joins the mark from 1200px. "" when the name is one word. */
    public function brandRest(): string
    {
        $words = preg_split('/\s+/', trim($this->doc->company()->name)) ?: [];

        return implode(' ', array_slice($words, 1));
    }

    /**
     * The footer's one wrapping line: every fact that is set, in one order,
     * separated by a middle dot; a blank field skips its slot.
     *
     * @return list<FootFact>
     */
    public function footFacts(): array
    {
        $doc   = $this->doc;
        $facts = [];

        foreach ([$doc->company()->name, $doc->company()->tagline] as $fact) {
            if ($fact !== '') {
                $facts[] = FootFact::plain($fact);
            }
        }

        if ($doc->content()->address !== '') {
            $facts[] = FootFact::plain($doc->content()->address);
        }

        if ($doc->company()->whatsapp !== '') {
            $facts[] = new FootFact('WhatsApp ', Format::fmtPhone($doc->company()->whatsapp), $doc->waHref(), true);
        }

        if ($doc->telHref() !== '') {
            $facts[] = new FootFact('Call ', $doc->company()->phone, $doc->telHref());
        }

        if ($doc->company()->email !== '') {
            $facts[] = new FootFact('', $doc->company()->email, 'mailto:' . $doc->company()->email);
        }

        if ($doc->content()->hours !== '') {
            $facts[] = FootFact::plain($doc->content()->hours);
        }

        if ($doc->version() > 0) {
            $when = Format::fmtDate($doc->publishedAt());
            if ($when !== '') {
                $facts[] = FootFact::plain('Rates updated ' . $when);
            }
        }

        $facts[] = FootFact::plain('© ' . ($this->year > 0 ? $this->year : (int) gmdate('Y')));

        return $facts;
    }

    /**
     * Which of the three head sources the layout must print. Exactly one, so
     * the page can never carry two titles or two JSON-LD blocks.
     */
    public function headSource(): string
    {
        if ($this->seo !== null) {
            return 'seo';
        }

        return $this->headHtml !== '' ? 'raw' : 'fallback';
    }

    /**
     * The `LocalBusiness` node, as data, for the FALLBACK head only.
     *
     * `Speedat\Domain\Seo\JsonLd` builds the real graph and `partials/seo`
     * prints it; this exists so that a page rendered without a `Head` still
     * carries the node the quality bar asks for on every page. It holds only
     * facts the document actually has - an invented rating, review or count is
     * the one thing the brief forbids outright.
     *
     * @return array<string,mixed>
     */
    public function localBusiness(): array
    {
        $doc = $this->doc;

        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            'name'     => $doc->company()->name,
        ];

        foreach (
            [
                'telephone'     => $doc->company()->phone,
                'email'         => $doc->company()->email,
                'address'       => $doc->content()->address,
                'openingHours'  => $doc->content()->hours,
            ] as $key => $value
        ) {
            if ($value !== '') {
                $node[$key] = $value;
            }
        }

        $areas = [];
        foreach ($doc->active() as $dest) {
            $areas[] = $dest->name;
        }
        if ($areas !== []) {
            $node['areaServed'] = $areas;
        }

        return $node;
    }
}
