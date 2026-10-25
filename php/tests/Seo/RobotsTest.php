<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Core\Config;
use Speedat\Domain\Seo\Robots;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/** /robots.txt: everything open but the back office, and the sitemap pointed at. */
final class RobotsTest extends TestCase
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

    private function text(): string
    {
        return (new Robots(Url::of('https://speedat.com.pk')))->text();
    }

    public function testItAllowsEverythingExceptTheAdminAndApiPaths(): void
    {
        $lines = explode("\n", $this->text());

        $this->assertSame('User-agent: *', $lines[0]);
        $this->assertTrue(in_array('Allow: /', $lines, true));
        $this->assertTrue(in_array('Disallow: /admin', $lines, true));
        $this->assertTrue(in_array('Disallow: /api', $lines, true));
    }

    public function testEveryDisallowedPrefixInTheRouteListIsInTheFile(): void
    {
        $text = $this->text();

        foreach (Routes::DISALLOWED as $prefix) {
            $this->assertStringContains('Disallow: ' . $prefix, $text);
        }

        // And nothing else is disallowed: one Disallow line per prefix.
        $this->assertSame(count(Routes::DISALLOWED), substr_count($text, 'Disallow:'));
    }

    public function testNoPublicPageIsAccidentallyBlocked(): void
    {
        $text = $this->text();

        foreach (Routes::all() as $route) {
            $this->assertFalse(Routes::isDisallowed($route->path), $route->path . ' is inside a disallowed prefix');
            if (!$route->isHome()) {
                $this->assertStringNotContains('Disallow: ' . $route->path, $text);
            }
        }
    }

    public function testItPointsAtTheSitemapWithAnAbsoluteUrl(): void
    {
        $this->assertStringContains('Sitemap: https://speedat.com.pk/sitemap.xml', $this->text());
    }

    public function testNoLocalhostUrlLeaksWhenTheSiteUrlIsConfigured(): void
    {
        Config::set('app.url', 'https://speedat.com.pk');

        $text = (new Robots(Url::fromConfig('http://localhost:8081')))->text();

        $this->assertStringNotContains('localhost', $text);
        $this->assertStringNotContains('127.0.0.1', $text);
        $this->assertStringContains('Sitemap: https://speedat.com.pk/sitemap.xml', $text);
    }

    public function testItIsServedAsPlainText(): void
    {
        $document = (new Robots(Url::of('https://speedat.com.pk')))->document();

        $this->assertSame('text/plain; charset=UTF-8', $document->contentType);
        $this->assertTrue(str_ends_with($document->body, "\n"), 'robots.txt should end with a newline');
    }
}
