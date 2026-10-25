<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Core\Paths;
use Speedat\Core\View;
use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/**
 * app/Views/partials/seo.php rendered for real, through the View, because the
 * escaping is only proven at the point where the values meet the markup.
 */
final class HeadPartialTest extends TestCase
{
    private const NONCE = 'test-nonce-123';

    private Url $url;

    public function setUp(): void
    {
        $this->url = Url::of('https://speedat.com.pk');
    }

    private function render(string $routeKey, ?SiteFacts $facts = null): string
    {
        $view = new View(Paths::views(), self::NONCE);
        $head = Head::forKey($facts ?? Fixtures::facts(), $this->url, $routeKey);

        return $view->partial('partials/seo', ['seo' => $head]);
    }

    public function testItRendersOneTitleAndOneCanonical(): void
    {
        $html = $this->render('faq');

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContains('<title>Questions - Speedat International Courier</title>', $html);
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContains('<link rel="canonical" href="https://speedat.com.pk/faq">', $html);
    }

    public function testItRendersTheWholeShareSet(): void
    {
        $html = $this->render('home');

        $this->assertStringContains('<meta name="description" content="', $html);
        $this->assertStringContains('<meta name="robots" content="index, follow', $html);
        $this->assertStringContains('<meta property="og:type" content="website">', $html);
        $this->assertStringContains('<meta property="og:site_name" content="Speedat International Courier">', $html);
        $this->assertStringContains('<meta property="og:url" content="https://speedat.com.pk/">', $html);
        $this->assertStringContains('<meta property="og:image" content="https://speedat.com.pk/assets/img/og-default.png">', $html);
        $this->assertStringContains('<meta property="og:image:type" content="image/png">', $html);
        $this->assertStringContains('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContains('<meta property="og:image:height" content="630">', $html);
        $this->assertStringContains('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function testTheJsonLdBlockIsPresentNoncedAndDecodable(): void
    {
        $html = $this->render('faq');

        $this->assertStringContains('<script type="application/ld+json" nonce="' . self::NONCE . '">', $html);

        $this->assertSame(1, preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m));
        $decoded = json_decode($m[1], true);

        $this->assertTrue(is_array($decoded), 'the embedded JSON-LD did not decode');
        $this->assertSame('https://schema.org', $decoded['@context']);
    }

    public function testAHostileDocumentCannotInjectAnythingIntoTheHead(): void
    {
        $html = $this->render('faq', Fixtures::hostile());

        // Asserted on the PARSED head, not on substrings. The literal text
        // "alert(1)" is still in the output - as "&lt;script&gt;alert(1)..."
        // inside a title, where it is text - so the only meaningful question
        // is what ELEMENTS and ATTRIBUTES a browser ends up with.
        $previous = libxml_use_internal_errors(true);
        $dom      = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head>' . $html . '</head><body></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $counts = [];
        /** @var \DOMElement $element */
        foreach ($dom->getElementsByTagName('*') as $element) {
            $counts[$element->nodeName] = ($counts[$element->nodeName] ?? 0) + 1;

            foreach ($element->attributes ?? [] as $attribute) {
                $this->assertFalse(
                    str_starts_with(strtolower($attribute->nodeName), 'on'),
                    'an event-handler attribute reached the markup: ' . $attribute->nodeName
                );
            }
        }

        $this->assertSame(1, $counts['script'] ?? 0, 'exactly one script element - the JSON-LD one');
        $this->assertSame(1, $counts['title'] ?? 0);
        $this->assertFalse(isset($counts['img']), 'an img element was injected');
        $this->assertFalse(isset($counts['iframe']));
        $this->assertFalse(isset($counts['style']));

        // The company name survives, escaped, inside the attribute.
        $this->assertStringContains('&lt;/script&gt;', $html);
        $this->assertStringContains('&amp; Co', $html);

        // And the JSON-LD still decodes with the hostile text intact.
        $this->assertSame(1, preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m));
        $this->assertTrue(is_array(json_decode($m[1], true)));
    }

    public function testAnAttributeValueWithAQuoteIsEscaped(): void
    {
        $html = $this->render('contact', Fixtures::hostile());

        // contactLede is: Call us & "book" <now>
        $this->assertStringContains('&quot;book&quot;', $html);
        $this->assertStringNotContains('content="Call us & "book"', $html);
    }

    public function testEveryPublicPageRendersAHead(): void
    {
        foreach (Routes::all() as $route) {
            $html = $this->render($route->key);

            $this->assertSame(1, substr_count($html, '<title>'), $route->key);
            $this->assertStringContains('rel="canonical"', $html, $route->key);
            $this->assertStringContains('application/ld+json', $html, $route->key);
        }
    }

    public function testAPageThatForgotItsHeadRendersNothingRatherThanHalfAHead(): void
    {
        $view = new View(Paths::views(), self::NONCE);

        $html = $view->partial('partials/seo', []);

        $this->assertStringNotContains('<title>', $html);
        $this->assertStringNotContains('<meta', $html);
    }
}
