<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Core\Config;
use Speedat\Domain\Seo\SeoError;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/**
 * The trailing-slash policy, the "no query in a canonical" policy, and the one
 * that matters most on the day the domain is pointed: `app.url` beats the
 * request, so a preview host or a spoofed Host header never gets written into
 * a canonical tag.
 */
final class UrlTest extends TestCase
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

    public function testTheBaseLosesItsTrailingSlashAndKeepsItsScheme(): void
    {
        $this->assertSame('https://speedat.com.pk', Url::of('https://speedat.com.pk/')->base());
        $this->assertSame('https://speedat.com.pk', Url::of('HTTPS://Speedat.Com.PK')->base());
        $this->assertSame('https://speedat.com.pk', Url::of('  https://speedat.com.pk  ')->base());
    }

    public function testADefaultPortIsDroppedAndANonDefaultPortIsKept(): void
    {
        $this->assertSame('https://speedat.com.pk', Url::of('https://speedat.com.pk:443')->base());
        $this->assertSame('http://127.0.0.1:8081', Url::of('http://127.0.0.1:8081')->base());
    }

    public function testASubdirectoryInstallKeepsItsBasePath(): void
    {
        $url = Url::of('https://staging.example.com/speedat/');

        $this->assertSame('https://staging.example.com/speedat', $url->base());
        $this->assertSame('https://staging.example.com/speedat/', $url->absolute('/'));
        $this->assertSame('https://staging.example.com/speedat/faq', $url->absolute('/faq'));
    }

    public function testOnlyTheHomePageKeepsATrailingSlash(): void
    {
        $url = Url::of('https://speedat.com.pk');

        $this->assertSame('https://speedat.com.pk/', $url->absolute('/'));
        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('/faq'));
        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('/faq/'));
        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('faq'));
    }

    public function testACanonicalNeverCarriesAQueryStringOrAFragment(): void
    {
        $url = Url::of('https://speedat.com.pk');

        $this->assertSame('https://speedat.com.pk/services', $url->absolute('/services?utm_source=whatsapp'));
        $this->assertSame('https://speedat.com.pk/services', $url->absolute('/services#rates'));
        $this->assertSame('https://speedat.com.pk/', $url->absolute('/?fbclid=123'));
    }

    public function testDoubleSlashesAndDotSegmentsAreNormalised(): void
    {
        $url = Url::of('https://speedat.com.pk');

        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('//faq//'));
        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('/services/../faq'));
        $this->assertSame('https://speedat.com.pk/faq', $url->absolute('/./faq'));
    }

    public function testAJunkBaseIsRefusedLoudly(): void
    {
        $this->assertThrows(SeoError::class, static fn () => Url::of(''));
        $this->assertThrows(SeoError::class, static fn () => Url::of('speedat.com.pk'));
        $this->assertThrows(SeoError::class, static fn () => Url::of('ftp://speedat.com.pk'));
        $this->assertThrows(SeoError::class, static fn () => Url::of('https://user:secret@speedat.com.pk'));
    }

    public function testTheConfiguredUrlBeatsTheRequestOrigin(): void
    {
        Config::set('app.url', 'https://speedat.com.pk');

        // What an attacker (or a preview domain) could put in the Host header.
        $url = Url::fromConfig('http://localhost:8081');

        $this->assertSame('https://speedat.com.pk', $url->base());
        $this->assertFalse($url->isLocal());
    }

    public function testTheRequestOriginIsOnlyUsedWhenTheConfiguredUrlIsEmpty(): void
    {
        Config::set('app.url', '');

        $url = Url::fromConfig('http://localhost:8081');

        $this->assertSame('http://localhost:8081', $url->base());
        $this->assertTrue($url->isLocal(), 'a development fallback must identify itself as local');
    }

    public function testDevelopmentHostsAreRecognised(): void
    {
        $this->assertTrue(Url::of('http://localhost:3020')->isLocal());
        $this->assertTrue(Url::of('http://127.0.0.1:8081')->isLocal());
        $this->assertTrue(Url::of('http://speedat.test')->isLocal());
        $this->assertTrue(Url::of('http://192.168.1.40')->isLocal());
        $this->assertFalse(Url::of('https://speedat.com.pk')->isLocal());
    }

    /**
     * parse_url() returns an IPv6 host WITH its brackets, so an isLocal() that
     * compares against "::1" was handed "[::1]" and answered false - the exact
     * false negative the guard exists to prevent. The brackets belong in the
     * authority and nowhere else.
     */
    public function testTheIpv6LoopbackIsRecognisedDespiteItsBrackets(): void
    {
        $url = Url::of('http://[::1]:8081');

        $this->assertSame('::1', $url->host());
        $this->assertSame('http://[::1]:8081', $url->base(), 'the authority keeps its brackets');
        $this->assertTrue($url->isLocal(), 'the IPv6 loopback is a development host');
    }

    public function testTheOtherAddressesThatMustNeverBePublishedAreLocalToo(): void
    {
        foreach (['http://0.0.0.0:8081', 'http://[fe80::1]', 'http://[fd00::42]', 'http://169.254.10.1',
                  'http://box.local', 'http://api.internal', 'http://speedat.localhost'] as $base) {
            $this->assertTrue(Url::of($base)->isLocal(), $base . ' must be recognised as a development address');
        }

        // Real hosts that merely look like one of the patterns above.
        foreach (['https://speedat.com.pk', 'https://fed.example.org', 'https://172.15.9.1', 'https://10x.pk'] as $base) {
            $this->assertFalse(Url::of($base)->isLocal(), $base . ' is a public host');
        }
    }

    /**
     * `Routes` used to carry its own copy of this, and the copy disagreed:
     * "//admin" survived it intact. One normaliser, asserted from both sides.
     */
    public function testThePathNormaliserIsTheOnlyOneAndCollapsesEverything(): void
    {
        $url = Url::of('https://speedat.com.pk');

        foreach (['//admin' => '/admin', '/./admin' => '/admin', '/admin//login' => '/admin/login',
                  '/faq/' => '/faq', '///' => '/', '/faq?a=1#b' => '/faq'] as $given => $want) {
            $this->assertSame($want, Url::normalise((string) $given));
            $this->assertSame($want, $url->path((string) $given), 'path() must agree with normalise()');
        }
    }
}
