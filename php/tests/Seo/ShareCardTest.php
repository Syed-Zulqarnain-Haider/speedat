<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Core\Paths;
use Speedat\Domain\Seo\ShareCard;
use Speedat\Domain\Seo\ShareCardRenderer;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Tests\TestCase;

/**
 * The share card: that the file we promise in `og:image` is actually there and
 * actually 1200x630, that what is written on it comes from the document, and
 * that the SVG cannot be broken by a quote in the company name.
 */
final class ShareCardTest extends TestCase
{
    private Url $url;

    private ShareCard $card;

    public function setUp(): void
    {
        $this->url  = Url::of('https://speedat.com.pk');
        $this->card = new ShareCard(Fixtures::facts(), $this->url);
    }

    public function testTheFileWePromiseIsActuallyShipped(): void
    {
        $file = Paths::publicDir() . '/' . ShareCard::CARD_PNG;

        $this->assertTrue(is_file($file), 'missing ' . ShareCard::CARD_PNG . ' - regenerate it, see docs/seo.md');
        $this->assertTrue($this->card->exists());

        $size = getimagesize($file);
        $this->assertTrue(is_array($size));
        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }

    /**
     * The first shipped card was drawn from the sample document and printed
     * the old domain, so every share of speedat.co advertised speedat.com.pk
     * (found 2026-10-25). The PNG cannot be read back here, but the SVG drawn
     * from the same plan can: it must exist and must not name the old host.
     */
    public function testTheShippedVectorCardIsThereAndNamesTheLiveDomain(): void
    {
        $file = Paths::publicDir() . '/' . ShareCard::CARD_SVG;

        $this->assertTrue(is_file($file), 'missing ' . ShareCard::CARD_SVG . ' - regenerate it, see docs/seo.md');

        $svg = (string) file_get_contents($file);
        $this->assertStringNotContains('speedat.com.pk', $svg);
        $this->assertStringContains('>speedat.co</text>', $svg);
    }

    public function testTheLogoIsShippedAndSquare(): void
    {
        $file = Paths::publicDir() . '/' . ShareCard::LOGO_PNG;

        $this->assertTrue(is_file($file), 'missing ' . ShareCard::LOGO_PNG);

        $size = getimagesize($file);
        $this->assertTrue(is_array($size));
        $this->assertSame(ShareCard::LOGO_SIZE, $size[0]);
        $this->assertSame(ShareCard::LOGO_SIZE, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }

    public function testTheDeclaredSizeMatchesTheFileOnDisk(): void
    {
        $size = getimagesize(Paths::publicDir() . '/' . ShareCard::CARD_PNG);

        $this->assertSame($this->card->width(), is_array($size) ? $size[0] : 0);
        $this->assertSame($this->card->height(), is_array($size) ? $size[1] : 0);
        $this->assertSame('image/png', $this->card->mimeType());
    }

    public function testItIsReferencedByAnAbsoluteUrl(): void
    {
        // From the constant, not a literal: renaming the card to bust the
        // scrapers' caches must not mean hunting filenames through the tests.
        $this->assertSame('https://speedat.com.pk/' . ShareCard::CARD_PNG, $this->card->absoluteUrl());
        $this->assertSame('https://speedat.com.pk/assets/img/og-logo.png', $this->card->logoAbsoluteUrl());
    }

    public function testWhatIsDrawnComesFromTheDocument(): void
    {
        $plan = $this->card->plan();

        $this->assertSame('Speedat International Courier', $plan['brand']);
        $this->assertSame('Speed Against Time', $plan['eyebrow']);
        $this->assertSame('Lahore to United Kingdom, United States, Canada and 7 more countries.', $plan['headline']);
        $this->assertSame('speedat.com.pk', $plan['footer']);
    }

    public function testATypedHeadlineBeatsTheGeneratedCountrySentence(): void
    {
        $document = Fixtures::array();
        $document['content']['heroTitle'] = 'Lahore to Australia, Canada, France and Germany.';

        $card = new ShareCard(new SiteFacts(SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z'), $this->url);

        $this->assertSame('Lahore to Australia, Canada, France and Germany.', $card->plan()['headline']);
    }

    public function testWithNoDestinationsItSaysTheTaglineRatherThanAClaim(): void
    {
        $document                 = Fixtures::array();
        $document['destinations'] = [];

        $card = new ShareCard(new SiteFacts(SiteData::fromArray($document)), $this->url);

        $this->assertSame('Speed Against Time', $card->plan()['headline']);
    }

    public function testTheAltTextSaysWhatTheCardSays(): void
    {
        $alt = $this->card->alt();

        $this->assertStringContains('Speedat International Courier', $alt);
        $this->assertStringContains('Lahore to United Kingdom', $alt);
    }

    public function testTheSvgIsWellFormedXml(): void
    {
        $dom = new \DOMDocument();

        $this->assertTrue($dom->loadXML($this->card->svg(), LIBXML_NONET), 'the card SVG is not well-formed');
        $this->assertSame('svg', $dom->documentElement?->nodeName);
        $this->assertSame('1200', $dom->documentElement?->getAttribute('width'));
        $this->assertSame('630', $dom->documentElement?->getAttribute('height'));
    }

    public function testTheLogoSvgIsWellFormedXml(): void
    {
        $dom = new \DOMDocument();

        $this->assertTrue($dom->loadXML($this->card->logoSvg(), LIBXML_NONET));
        $this->assertSame('512', $dom->documentElement?->getAttribute('width'));
    }

    public function testAQuoteInTheCompanyNameCannotBreakTheSvg(): void
    {
        $card = new ShareCard(Fixtures::hostile(), $this->url);
        $svg  = $card->svg();

        $this->assertStringNotContains('<script>', $svg);
        $this->assertStringNotContains('onerror=', $svg);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($svg, LIBXML_NONET), 'hostile content broke the SVG');
    }

    public function testTheWrapNeverExceedsItsLineBudget(): void
    {
        $long = str_repeat('Lahore to Australia and Canada and Germany ', 6);

        $this->assertNull(ShareCard::wrapByChars($long, 30, 3));

        $forced = ShareCard::wrapByChars($long, 30, 3, true);
        $this->assertTrue(is_array($forced));
        $this->assertSame(3, count($forced ?? []));
    }

    public function testTheRendererReproducesTheShippedCard(): void
    {
        $font = ShareCardRenderer::findFont(Paths::root());

        if ($font === null) {
            $this->skip('no TrueType font was found on this machine, so GD cannot redraw the card');
        }
        if (!extension_loaded('gd')) {
            $this->skip('the gd extension is not loaded');
        }

        $png  = (new ShareCardRenderer($this->card, $font))->png();
        $size = getimagesizefromstring($png);

        $this->assertTrue(is_array($size));
        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }
}
