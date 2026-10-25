<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\JsonLd;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\ShareCard;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Tests\TestCase;

/**
 * The structured data, checked for the two things that actually go wrong with
 * it: saying something the page does not say, and escaping.
 */
final class JsonLdTest extends TestCase
{
    private Url $url;

    private SiteFacts $facts;

    private JsonLd $jsonLd;

    public function setUp(): void
    {
        $this->url    = Url::of('https://speedat.com.pk');
        $this->facts  = Fixtures::facts();
        $this->jsonLd = $this->ldFor($this->facts);
    }

    private function ldFor(SiteFacts $facts): JsonLd
    {
        return new JsonLd($facts, $this->url, new ShareCard($facts, $this->url));
    }

    /** @return array<string,mixed> the decoded graph, keyed by @type */
    private function nodes(string $routeKey, ?JsonLd $ld = null): array
    {
        $json    = ($ld ?? $this->jsonLd)->render(Routes::byKey($routeKey));
        $decoded = json_decode($json, true);

        $this->assertTrue(is_array($decoded), 'the JSON-LD did not decode: ' . json_last_error_msg());

        $out = [];
        foreach ($decoded['@graph'] ?? [] as $node) {
            $type = $node['@type'] ?? '';
            if ($type === 'Service') {
                $out['Service'][] = $node;
                continue;
            }
            $out[$type] = $node;
        }

        return $out;
    }

    public function testTheGraphDecodesAndDeclaresSchemaOrg(): void
    {
        $decoded = json_decode($this->jsonLd->render(Routes::byKey('home')), true);

        $this->assertTrue(is_array($decoded));
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertTrue(is_array($decoded['@graph']));
    }

    public function testTheLocalBusinessCarriesEverythingTheQualityBarNames(): void
    {
        $business = $this->nodes('home')['LocalBusiness'];

        $this->assertSame('Speedat International Courier', $business['name']);
        $this->assertSame('https://speedat.com.pk/#business', $business['@id']);
        $this->assertSame('https://speedat.com.pk/', $business['url']);
        $this->assertSame('+92 315 766 7076', $business['telephone']);
        $this->assertSame('info@speedat.net', $business['email']);
        $this->assertSame('Speed Against Time', $business['slogan']);
        $this->assertSame('PKR', $business['currenciesAccepted']);
        $this->assertStringContains('PKR', (string) $business['priceRange']);
        $this->assertSame('https://speedat.com.pk/assets/img/og-logo.png', $business['logo']['url']);
        $this->assertSame('https://speedat.com.pk/assets/img/og-default.png', $business['image']);
    }

    public function testTheAddressIsSplitOutOfTheOwnersOneLineAddress(): void
    {
        $address = $this->nodes('home')['LocalBusiness']['address'];

        $this->assertSame('PostalAddress', $address['@type']);
        $this->assertSame('Lahore', $address['addressLocality']);
        $this->assertSame('Pakistan', $address['addressCountry']);
        $this->assertSame(
            'Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model Town',
            $address['streetAddress']
        );
    }

    public function testTheOpeningHoursAreStructuredNotProse(): void
    {
        $spec = $this->nodes('home')['LocalBusiness']['openingHoursSpecification'];

        $this->assertSame(1, count($spec));
        $this->assertSame('09:00', $spec[0]['opens']);
        $this->assertSame('19:00', $spec[0]['closes']);
        $this->assertSame(6, count($spec[0]['dayOfWeek']));
        $this->assertSame('Monday', $spec[0]['dayOfWeek'][0]);
        $this->assertSame('Saturday', $spec[0]['dayOfWeek'][5]);
    }

    public function testAreaServedIsTheLiveDestinationListInOrder(): void
    {
        $areas = $this->nodes('home')['LocalBusiness']['areaServed'];
        $names = array_map(static fn (array $a): string => $a['name'], $areas);

        $this->assertSame($this->facts->countryNames(), $names);
        $this->assertSame(10, count($names));
        $this->assertSame('Country', $areas[0]['@type']);
    }

    public function testAHiddenDestinationIsNotClaimedAsAreaServed(): void
    {
        $document = Fixtures::array();
        $document['destinations'][3]['active'] = false; // Australia

        $facts = new SiteFacts(\Speedat\Domain\SiteDoc\SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z');
        $areas = $this->nodes('home', $this->ldFor($facts))['LocalBusiness']['areaServed'];
        $names = array_map(static fn (array $a): string => $a['name'], $areas);

        $this->assertSame(9, count($names));
        $this->assertFalse(in_array('Australia', $names, true));
    }

    public function testWhatsAppIsAContactPoint(): void
    {
        $business = $this->nodes('home')['LocalBusiness'];

        $this->assertSame('+923157667076', $business['contactPoint'][0]['telephone']);
        $this->assertSame('https://wa.me/923157667076', $business['contactPoint'][0]['url']);
        $this->assertSame('customer service', $business['contactPoint'][0]['contactType']);
    }

    public function testThePriceRangeIsTheOneThePricingEngineProduces(): void
    {
        $business = $this->nodes('home')['LocalBusiness'];

        // The cheapest 1 kg parcel on the site is United Arab Emirates on
        // Normal: 2400 + 600 + the 500 pickup add-on. The dearest is Australia
        // on Normal: 4000 + 1000 + 500. Both are what a country tile prints.
        $this->assertSame('PKR 3,500-PKR 5,500', $business['priceRange']);
        $this->assertSame($this->facts->priceRange(), $business['priceRange']);
    }

    public function testTheFaqJsonLdMatchesTheRenderedAnswersOneForOne(): void
    {
        $questions = $this->nodes('faq')['FAQPage']['mainEntity'];
        $rendered  = $this->facts->faqItems();

        $this->assertSame(5, count($rendered));
        $this->assertSame(count($rendered), count($questions));

        foreach ($rendered as $i => $item) {
            $this->assertSame('Question', $questions[$i]['@type']);
            $this->assertSame($item['question'], $questions[$i]['name']);
            $this->assertSame('Answer', $questions[$i]['acceptedAnswer']['@type']);
            $this->assertSame($item['answer'], $questions[$i]['acceptedAnswer']['text']);
        }
    }

    public function testTheFaqPageIsOnlyOnTheFaqPage(): void
    {
        foreach (['home', 'services', 'about', 'contact'] as $key) {
            $this->assertFalse(isset($this->nodes($key)['FAQPage']), $key . ' emitted a FAQPage it does not render');
        }
    }

    public function testAnEmptyFaqFieldEmitsNoFaqPageRatherThanAnEmptyOne(): void
    {
        $document                = Fixtures::array();
        $document['content']['faq'] = '';

        $facts = new SiteFacts(\Speedat\Domain\SiteDoc\SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z');

        $this->assertFalse(isset($this->nodes('faq', $this->ldFor($facts))['FAQPage']));
    }

    public function testTheServicesAreTheOnesTheServicesPageRenders(): void
    {
        $services = $this->nodes('services')['Service'];
        $rendered = $this->facts->serviceItems();

        $this->assertSame(count($rendered), count($services));

        $names = array_map(static fn (array $s): string => $s['name'], $services);
        foreach (['Express', 'Normal', 'Documents', 'Cargo'] as $expected) {
            $this->assertTrue(in_array($expected, $names, true), 'no Service entry for ' . $expected);
        }

        $this->assertSame('https://speedat.com.pk/services#service-express', $services[0]['@id']);
        $this->assertSame('https://speedat.com.pk/#business', $services[0]['provider']['@id']);
        $this->assertSame($rendered[0]['description'], $services[0]['description']);
    }

    /**
     * A Service NAMED "Documents" is right - the company sends documents, and
     * Google should know it. A Service that PRICES them is not: the owner
     * removed the document rate from the product on 2026-09-27. The fixture is
     * still a pre-2026-09-27 document, `docMaxKg` and per-rate `doc` prices and
     * all, so this asserts the graph built from one advertises neither.
     */
    public function testNoNodeInTheGraphAdvertisesADocumentRate(): void
    {
        $banned = ['flat rate', 'flat document', 'document rate', 'per envelope', 'documents up to'];

        foreach (['home', 'services', 'about', 'contact', 'faq'] as $key) {
            $json = mb_strtolower($this->jsonLd->render(Routes::byKey($key)));

            foreach ($banned as $phrase) {
                $this->assertStringNotContains($phrase, $json, $key . ' JSON-LD still sells a document rate');
            }
        }
    }

    public function testServicesAreOnlyOnTheServicesPage(): void
    {
        foreach (['home', 'about', 'contact', 'faq'] as $key) {
            $this->assertFalse(isset($this->nodes($key)['Service']), $key . ' emitted Service entries');
        }
    }

    public function testInnerPagesCarryABreadcrumbTrailAndTheHomePageDoesNot(): void
    {
        $this->assertFalse(isset($this->nodes('home')['BreadcrumbList']));

        $trail = $this->nodes('faq')['BreadcrumbList'];

        $this->assertSame(2, count($trail['itemListElement']));
        $this->assertSame('Home', $trail['itemListElement'][0]['name']);
        $this->assertSame('https://speedat.com.pk/', $trail['itemListElement'][0]['item']);
        $this->assertSame(1, $trail['itemListElement'][0]['position']);
        $this->assertSame('Questions', $trail['itemListElement'][1]['name']);
        $this->assertSame('https://speedat.com.pk/faq', $trail['itemListElement'][1]['item']);
        $this->assertSame(2, $trail['itemListElement'][1]['position']);
    }

    public function testNothingIsEmittedWithAnEmptyValue(): void
    {
        $document                     = Fixtures::array();
        $document['company']['email'] = '';
        $document['company']['phone'] = '';
        $document['content']['hours'] = 'whenever we feel like it';

        $facts    = new SiteFacts(\Speedat\Domain\SiteDoc\SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z');
        $business = $this->nodes('home', $this->ldFor($facts))['LocalBusiness'];

        $this->assertFalse(isset($business['email']), 'an empty email must be absent, not blank');
        // No landline: the WhatsApp number stands in rather than nothing.
        $this->assertSame('+923157667076', $business['telephone']);
        // Unparseable hours: omitted rather than guessed.
        $this->assertFalse(isset($business['openingHoursSpecification']));
    }

    public function testNothingCanBreakOutOfTheScriptTag(): void
    {
        $facts  = Fixtures::hostile();
        $json   = $this->ldFor($facts)->render(Routes::byKey('faq'));

        $this->assertStringNotContains('</script>', $json);
        $this->assertStringNotContains('<script', $json);
        $this->assertStringNotContains('<img', $json);
        $this->assertStringContains('\\u003C', $json, 'the angle brackets should be hex-escaped, not stripped');

        // Still valid JSON, and the value is preserved exactly - escaped, not mangled.
        $decoded = json_decode($json, true);
        $this->assertTrue(is_array($decoded));

        $questions = $this->nodes('faq', $this->ldFor($facts))['FAQPage']['mainEntity'];
        $this->assertSame($facts->faqItems()[0]['answer'], $questions[0]['acceptedAnswer']['text']);
    }

    public function testTheEncoderEscapesAmpersandsAndQuotesToo(): void
    {
        $json = JsonLd::encode(['a' => '& " \' <']);

        $this->assertSame('{"a":"\\u0026 \\u0022 \\u0027 \\u003C"}', $json);
    }
}
