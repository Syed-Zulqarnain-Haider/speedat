<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\JsonLd;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\ShareCard;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Domain\SiteDoc\Review;
use Speedat\Domain\SiteDoc\Reviews;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Tests\TestCase;

/**
 * The 2026-10-25 audit's structured-data and seed findings, one test each:
 * placeholder reviews that went out as real ones, review markup on pages that
 * show no reviews, a Pickup service that claimed to serve Canada, the
 * "Express and Normal and Express Saver" description, and the Documents line
 * that pointed at a table row that no longer exists.
 */
final class B10StructuredDataTest extends TestCase
{
    private Url $url;

    public function setUp(): void
    {
        $this->url = Url::of('https://speedat.co');
    }

    // ---- the seed's placeholder reviews ------------------------------------

    public function testTheSeedShipsItsPlaceholderReviewsSwitchedOff(): void
    {
        foreach (Seed::reviews() as $i => $review) {
            $this->assertFalse($review['show'], 'seed review ' . $i . ' ships switched on');
        }

        // Which is what a fresh site and the seed fallback render: nothing.
        $this->assertSame([], Seed::site()->reviews->shown());
    }

    /**
     * Documents already published with the placeholders switched on must not
     * print them either - the backstop in `Reviews::shown()`.
     */
    public function testAPlaceholderSwitchedOnIsStillNotShown(): void
    {
        $reviews = new Reviews([
            new Review(author: Reviews::SAMPLE_AUTHOR, rating: 5, text: 'Fast.', show: true),
            new Review(author: 'sample review ', rating: 5, text: 'Quick.', show: true),
            new Review(author: 'Ayesha K.', rating: 4, text: 'Good service.', show: true),
        ]);

        $shown = $reviews->shown();

        $this->assertCount(1, $shown);
        $this->assertSame('Ayesha K.', $shown[0]->author);
    }

    public function testNoAggregateRatingIsBuiltFromPlaceholders(): void
    {
        $document            = Fixtures::array();
        $document['reviews'] = array_map(
            static fn (array $r): array => ['show' => true] + $r,
            Seed::reviews(),
        );

        $business = $this->business('home', $this->facts($document));

        $this->assertFalse(isset($business['aggregateRating']), 'placeholder reviews became an AggregateRating');
        $this->assertFalse(isset($business['review']));
    }

    // ---- review markup only where reviews are visible ----------------------

    public function testReviewMarkupIsOnTheHomePageOnly(): void
    {
        $document            = Fixtures::array();
        $document['reviews'] = [
            ['author' => 'Ayesha K.', 'rating' => 5, 'text' => 'Parcel reached Leeds in four days.', 'date' => '', 'show' => true],
        ];
        $facts = $this->facts($document);

        $home = $this->business('home', $facts);
        $this->assertSame(1, $home['aggregateRating']['reviewCount'] ?? null);
        $this->assertSame('Ayesha K.', $home['review'][0]['author']['name'] ?? null);

        // Only home.php renders the reviews strip, so only / may mark it up.
        foreach (['services', 'about', 'contact', 'faq'] as $key) {
            $node = $this->business($key, $facts);
            $this->assertFalse(isset($node['aggregateRating']), $key . ' marks up a rating it does not show');
            $this->assertFalse(isset($node['review']), $key . ' marks up reviews it does not show');
        }
    }

    // ---- Service nodes ----------------------------------------------------

    public function testServiceNodesClaimNoDestinationCountries(): void
    {
        $facts    = Fixtures::facts();
        $services = $this->ld($facts)->services(Routes::byKey('services'));

        $this->assertTrue($services !== [], 'the fixture lists services');

        foreach ($services as $service) {
            $this->assertFalse(isset($service['areaServed']), $service['name'] . ' claims an areaServed');
        }

        // The LocalBusiness they all point at still carries the whole area.
        $this->assertTrue(isset($this->business('services', $facts)['areaServed']));
    }

    // ---- the site description ---------------------------------------------

    public function testThreeServicesAreJoinedWithCommasAndOneAnd(): void
    {
        $document               = Fixtures::array();
        $document['services'][] = ['id' => 'saver', 'name' => 'Express Saver', 'note' => ''];

        $description = $this->facts($document)->siteDescription();

        $this->assertStringContains('Express, Normal and Express Saver courier prices from Lahore', $description);
        $this->assertStringNotContains(' and Normal and ', $description);
    }

    public function testThePlainListNamesEveryEntry(): void
    {
        $this->assertSame('', SiteFacts::plainList([]));
        $this->assertSame('Express', SiteFacts::plainList(['Express']));
        $this->assertSame('Express and Normal', SiteFacts::plainList(['Express', 'Normal']));
        // Never folds into "and N more", unlike countryList().
        $this->assertSame('A, B, C, D, E and F', SiteFacts::plainList(['A', 'B', 'C', 'D', 'E', 'F']));
    }

    // ---- seed copy --------------------------------------------------------

    public function testTheDocumentsLinePointsAtNoTableRow(): void
    {
        $services = Seed::content()['services'];

        $this->assertStringNotContains('row in the table', $services);
        $this->assertStringContains('Documents', $services);
    }

    // ---- helpers ----------------------------------------------------------

    /** @param array<string,mixed> $document */
    private function facts(array $document): SiteFacts
    {
        return new SiteFacts(SiteData::fromArray($document), 7, '2026-10-20T06:15:00Z');
    }

    private function ld(SiteFacts $facts): JsonLd
    {
        return new JsonLd($facts, $this->url, new ShareCard($facts, $this->url));
    }

    /** @return array<string,mixed> the LocalBusiness node of that page's graph */
    private function business(string $routeKey, SiteFacts $facts): array
    {
        $decoded = json_decode($this->ld($facts)->render(Routes::byKey($routeKey)), true);
        $this->assertTrue(is_array($decoded), 'the JSON-LD did not decode');

        foreach ($decoded['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? '') === 'LocalBusiness') {
                return $node;
            }
        }

        $this->fail('no LocalBusiness on ' . $routeKey);
    }
}
