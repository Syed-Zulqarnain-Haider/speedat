<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\View\PageView;

/**
 * The five customer pages, over HTTP, through the real routing table.
 *
 * `tests/View/SiteTemplatesTest.php` already proves the templates escape and
 * render; this proves they are REACHABLE and that what the wiring adds around
 * them is right - the status, the content type, the caching, the head the SEO
 * layer built rather than the layout's fallback, the theme from the cookie, and
 * the scripts each page is given.
 *
 * None of it needs a database: an unreachable one serves the sample document
 * (`SiteDoc\Live`), which is a page with sample prices rather than a 500.
 */
final class PublicPagesTest extends HttpTestCase
{
    /** @return array<string,string> route => path */
    private const PAGES = [
        'home'     => '/',
        'services' => '/services',
        'about'    => '/about',
        'contact'  => '/contact',
        'faq'      => '/faq',
    ];

    public function testEveryPageAnswers200AsHtml(): void
    {
        foreach (self::PAGES as $name => $path) {
            $answer = $this->get($path);

            $this->assertStatus(200, $answer, $name);
            $this->assertContentType('text/html; charset=utf-8', $answer, $name);
            $this->assertStringContains('<!doctype html>', $answer->body(), $name);
            $this->assertStringContains('</html>', $answer->body(), $name);
        }
    }

    /**
     * A price the owner has just withdrawn must not be served out of a cache.
     * The hold can come on between two requests, so no HTML page is storable.
     */
    public function testNoPageIsCacheable(): void
    {
        foreach (self::PAGES as $name => $path) {
            $this->assertStringContains('no-store', $this->get($path)->header('Cache-Control'), $name);
        }
    }

    public function testEveryPageHasOneH1AndAWholeShell(): void
    {
        foreach (self::PAGES as $name => $path) {
            $answer = $this->get($path);

            $this->assertOneH1($answer, $name);
            $this->assertStringContains('class="site-head"', $answer->body(), $name);
            $this->assertStringContains('<main>', $answer->body(), $name);
            $this->assertStringContains('class="site-foot"', $answer->body(), $name);
        }
    }

    /**
     * THE HEAD IS THE SEO LAYER'S, not the layout's fallback.
     *
     * The tell is the fallback's own robots tag, which `Domain\Seo\Meta` does
     * not print: if it appears, a controller forgot to build a `Head` and the
     * page is carrying a canonical assembled from the request path instead of
     * from `app.url`.
     */
    public function testEveryPageCarriesTheSeoHeadAndNotTheFallback(): void
    {
        foreach (self::PAGES as $name => $path) {
            $answer = $this->get($path);

            $this->assertHeadIsWhole($answer, $name);
            $this->assertStringNotContains(
                '<meta name="robots" content="index, follow">',
                $answer->body(),
                $name . ': the layout fallback head rendered, so no Head was built',
            );
            $this->assertStringContains('content="index, follow, max-image-preview:large', $answer->body(), $name);
        }
    }

    public function testEveryPageHasItsOwnTitleAndCanonical(): void
    {
        $titles     = [];
        $canonicals = [];

        foreach (self::PAGES as $name => $path) {
            $html = $this->get($path)->body();

            preg_match('#<title>(.*?)</title>#s', $html, $title);
            preg_match('#<link rel="canonical" href="([^"]*)"#', $html, $canonical);

            $titles[$name]     = $title[1] ?? '';
            $canonicals[$name] = $canonical[1] ?? '';

            $this->assertNotSame('', $titles[$name], $name . ': no title');
            $this->assertStringContains('http', $canonicals[$name], $name . ': the canonical is not absolute');
        }

        $this->assertSame(count(self::PAGES), count(array_unique($titles)), 'two pages share a title');
        $this->assertSame(count(self::PAGES), count(array_unique($canonicals)), 'two pages share a canonical');

        // The home page is the one URL that keeps its slash.
        $this->assertTrue(str_ends_with($canonicals['home'], '/'), 'the home canonical is the bare origin with a slash');
        $this->assertTrue(str_ends_with($canonicals['faq'], '/faq'), 'no trailing slash on an inner page');
    }

    /**
     * The canonical comes from `app.url`, never from the Host header.
     *
     * A request on the IP, on a preview domain, or with a forged Host must still
     * emit the real address - otherwise anyone who can send a header can decide
     * what a page is indexed as.
     */
    public function testAForgedHostHeaderDoesNotReachTheCanonical(): void
    {
        $answer = $this->get('/faq', [], ['host' => 'evil.example']);

        $this->assertStatus(200, $answer);
        $this->assertStringNotContains('evil.example', $answer->body(), 'the Host header reached the head');
    }

    public function testTheJsonLdOnEveryPageParsesAndNamesTheBusiness(): void
    {
        foreach (self::PAGES as $name => $path) {
            $graph = $this->jsonLd($this->get($path));

            $this->assertSame('https://schema.org', $graph['@context'] ?? null, $name);
            $types = [];
            foreach (is_array($graph['@graph'] ?? null) ? $graph['@graph'] : [] as $node) {
                if (is_array($node) && is_string($node['@type'] ?? null)) {
                    $types[] = $node['@type'];
                }
            }
            $this->assertTrue(in_array('LocalBusiness', $types, true), $name . ': no LocalBusiness node');
        }
    }

    /** The FAQ's structured data comes from the same field the page prints. */
    public function testTheFaqPageCarriesTheFaqPageNode(): void
    {
        $this->assertStringContains('"@type":"FAQPage"', $this->get('/faq')->body());
    }

    /**
     * The theme is decided on the server, from the cookie, so the first paint is
     * already right. `ui.js` writes the same cookie; nothing in the browser
     * decides it.
     */
    public function testTheThemeComesFromTheCookie(): void
    {
        $this->assertStringContains(
            '<html lang="en" data-theme="dark">',
            $this->get('/', [], [], ['theme' => 'dark'])->body(),
            'the dark cookie',
        );

        $this->assertStringContains(
            '<html lang="en" data-theme="light">',
            $this->get('/', [], [], ['theme' => 'neon-pink'])->body(),
            'an unknown value is light, never a broken attribute',
        );

        $this->assertStringContains(
            '<html lang="en" data-theme="light">',
            $this->get('/')->body(),
            'no cookie at all',
        );
    }

    /**
     * The quote page gets the engine, the helpers and the calculator, in that
     * order - they are `defer`, so document order is execution order and
     * calculator.js needs the other two to be there already.
     */
    public function testTheHomePageGetsTheCalculatorsThreeScriptsInOrder(): void
    {
        preg_match_all('#<script defer src="/assets/([^"?]+)#', $this->get('/')->body(), $m);

        $this->assertSame(PageView::CALCULATOR_SCRIPTS, $m[1], 'the three scripts, in the one order that works');
        $this->assertStringContains('id="speedat-calculator"', $this->get('/')->body(), 'the calculator blob');
    }

    /** Every other page gets one script, because none of them prices anything. */
    public function testTheInnerPagesGetOnlyTheHelpers(): void
    {
        foreach (['/services', '/about', '/contact', '/faq'] as $path) {
            preg_match_all('#<script defer src="/assets/([^"?]+)#', $this->get($path)->body(), $m);

            $this->assertSame(['js/ui.js'], $m[1], $path);
            $this->assertStringNotContains('id="speedat-calculator"', $this->get($path)->body(), $path);
        }
    }

    /** The health check says the PHP is running and nothing else. */
    public function testHealthAnswersWithoutTheToken(): void
    {
        $answer = $this->get('/health');

        $this->assertStatus(200, $answer);
        $this->assertContentType('application/json; charset=utf-8', $answer);
        $this->assertSame(['ok' => true], $answer->json(), 'a health endpoint that names the broken component is a map');
    }
}
