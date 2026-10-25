<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\JsonLd;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\ShareCard;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Tests\TestCase;

/**
 * The price hold has to reach the structured data.
 *
 * A browser walk found the one number that survived a hold: `priceRange` in
 * the LocalBusiness block. Everything a visitor could read was gone, but the
 * copy a search engine reads still published "PKR 3,500-PKR 5,500" - which is
 * exactly what the hold exists to withdraw, and a search result outlives the
 * hold by days.
 */
final class HoldPriceRangeTest extends TestCase
{
    public function testAHeldSitePublishesNoPriceRange(): void
    {
        $held = self::facts(false);

        $this->assertNull($held->priceRange(), 'a held site must not compute a range');

        $json = self::render($held);
        $this->assertStringNotContains('priceRange', $json);
        // The guard must not overreach: the business is still the business.
        $this->assertStringContains('LocalBusiness', $json);
    }

    public function testAVisibleSitePublishesItsRangeAsBefore(): void
    {
        $shown = self::facts(true);

        $range = $shown->priceRange();
        $this->assertNotNull($range);
        $this->assertStringContains('PKR', (string) $range);
        $this->assertStringContains('priceRange', self::render($shown));
    }

    public function testTheHoldDoesNotSilenceAnythingElseTheBusinessBlockCarries(): void
    {
        $json = self::render(self::facts(false));

        // The hold withdraws prices, not the company: its name and the
        // countries it serves are still true while rates are being keyed in.
        $this->assertStringContains('Speedat', $json);
        $this->assertStringContains('areaServed', $json);
    }

    private static function facts(bool $pricesVisible): SiteFacts
    {
        return new SiteFacts(SiteData::fromArray(Seed::data()), 7, '2026-09-27T10:00:00+00:00', $pricesVisible);
    }

    /** The home page's JSON-LD exactly as the layout prints it. */
    private static function render(SiteFacts $facts): string
    {
        $url = Url::of('https://speedat.example');

        return (new JsonLd($facts, $url, new ShareCard($facts, $url)))->render(Routes::byKey('home'));
    }
}
