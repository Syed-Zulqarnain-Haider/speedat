<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Core\Config;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\Sitemap;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/**
 * /sitemap.xml, validated against the sitemaps.org 0.9 schema rather than
 * eyeballed, and checked for the two mistakes that are expensive and silent: a
 * localhost URL published to a crawler, and an admin path listed for one.
 */
final class SitemapTest extends TestCase
{
    private string $configured = '';

    public function setUp(): void
    {
        $this->configured = Config::str('app.url');
    }

    public function tearDown(): void
    {
        Config::set('app.url', $this->configured);
    }

    private function xml(): string
    {
        return (new Sitemap(Fixtures::facts(), Url::of('https://speedat.com.pk')))->xml();
    }

    public function testItValidatesAgainstTheSitemapSchema(): void
    {
        if (!class_exists(\DOMDocument::class)) {
            $this->skip('the dom extension is not loaded, so the schema cannot be checked');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($this->xml(), LIBXML_NONET), 'the sitemap is not well-formed XML');

        $valid  = $dom->schemaValidate(__DIR__ . '/fixtures/sitemap.xsd');
        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, 'schema errors: ' . implode(' | ', $errors));
    }

    public function testItListsEveryPublicRouteAndNothingElse(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->xml(), LIBXML_NONET);

        $locations = [];
        foreach ($dom->getElementsByTagNameNS(Sitemap::NAMESPACE_URI, 'loc') as $node) {
            $locations[] = $node->textContent;
        }

        $this->assertSame(count(Routes::all()), count($locations));
        $this->assertSame('https://speedat.com.pk/', $locations[0]);

        foreach (Routes::all() as $i => $route) {
            $expected = $route->isHome() ? 'https://speedat.com.pk/' : 'https://speedat.com.pk' . $route->path;
            $this->assertSame($expected, $locations[$i]);
        }
    }

    public function testTheAdminAndApiPathsAreNowhereInIt(): void
    {
        $xml = $this->xml();

        foreach (Routes::DISALLOWED as $prefix) {
            $this->assertStringNotContains('speedat.com.pk' . $prefix, $xml);
        }

        $this->assertStringNotContains('/admin', $xml);
        $this->assertStringNotContains('/api', $xml);
        $this->assertStringNotContains('/health', $xml);
    }

    public function testLastmodIsThePublishedVersionsTimestamp(): void
    {
        $xml = $this->xml();

        $this->assertStringContains('<lastmod>2026-09-20T06:15:00Z</lastmod>', $xml);
        $this->assertSame(count(Routes::all()), substr_count($xml, '<lastmod>'));
    }

    public function testAnUnpublishedDocumentClaimsNoDateAtAll(): void
    {
        $xml = (new Sitemap(Fixtures::unpublished(), Url::of('https://speedat.com.pk')))->xml();

        $this->assertStringNotContains('<lastmod>', $xml);

        // Still a valid sitemap without it.
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $this->assertTrue($dom->schemaValidate(__DIR__ . '/fixtures/sitemap.xsd'));
    }

    public function testNoLocalhostUrlLeaksWhenTheSiteUrlIsConfigured(): void
    {
        Config::set('app.url', 'https://speedat.com.pk');

        // The request arrives on the dev server; the configured URL must win.
        $xml = (new Sitemap(Fixtures::facts(), Url::fromConfig('http://localhost:8081')))->xml();

        $this->assertStringNotContains('localhost', $xml);
        $this->assertStringNotContains('127.0.0.1', $xml);
        $this->assertStringNotContains(':8081', $xml);
        $this->assertStringContains('https://speedat.com.pk/', $xml);
    }

    public function testEveryUrlIsAbsoluteAndHttps(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->xml(), LIBXML_NONET);

        foreach ($dom->getElementsByTagNameNS(Sitemap::NAMESPACE_URI, 'loc') as $node) {
            $this->assertTrue(str_starts_with($node->textContent, 'https://'), $node->textContent . ' is not absolute https');
            $this->assertStringNotContains('?', $node->textContent);
        }
    }

    public function testItIsServedAsXml(): void
    {
        $document = (new Sitemap(Fixtures::facts(), Url::of('https://speedat.com.pk')))->document();

        $this->assertSame('application/xml; charset=UTF-8', $document->contentType);
        $this->assertTrue(str_starts_with($document->body, '<?xml version="1.0" encoding="UTF-8"?>'));
    }

    public function testOwnerTypedCharactersCannotMalformTheXml(): void
    {
        // Nothing owner-typed reaches a <loc> today, but the escaping is what
        // guarantees that stays true if a route label ever does.
        $xml = $this->xml();

        $this->assertSame(substr_count($xml, '<url>'), substr_count($xml, '</url>'));
        $this->assertSame(1, substr_count($xml, '<urlset'));
    }
}
