<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\LocalAreas;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\View\Doc;
use Speedat\View\HomeView;
use Speedat\View\PageView;

/**
 * NO "FREE PICKUP" ON A SITE THAT CHARGES FOR PICKUP.
 *
 * 2026-10-26 - owner: the pickup charge is shown under the quote, so the site
 * must not also say pickup is free. "Pickup | 1000 | separate" prints "Pickup
 * charge: PKR 1,000 - paid separately" under every quote; before this, the
 * same home page also said "Free pickup from anywhere in Lahore", the meta,
 * og, twitter and JSON-LD descriptions said "Free pickup across Lahore", and
 * the terms said "We offer free pickup within Lahore". All of them now read
 * LocalAreas::pickupCharged(), so they flip together.
 *
 * Rendered for real: the home page with the production head (Head::forKey,
 * the pair a controller uses, so the meta tags and the JSON-LD are in the
 * HTML), and the terms page. "Anywhere in the HTML" is the assertion, because
 * a claim in any one of those places is the contradiction.
 *
 * The other direction is pinned just as hard: a document that charges
 * nothing for pickup keeps today's exact wording, word for word.
 */
final class FreePickupClaimTest extends ViewTestCase
{
    private const FREE_DESCRIPTION =
        'Express and Economy courier prices from Lahore by country and weight. Free pickup across Lahore '
        . '- DHA, Bahria Town, Model Town and Johar Town. Book on WhatsApp.';

    private function withAddons(string $addons): Doc
    {
        return $this->doc(['settings' => ['addons' => $addons]]);
    }

    private function facts(Doc $doc): SiteFacts
    {
        return new SiteFacts($doc->data(), $doc->version(), $doc->publishedAt());
    }

    private function home(Doc $doc): string
    {
        $page = new PageView(
            doc: $doc,
            path: '/',
            title: 'Get a price',
            seo: Head::forKey($this->facts($doc), Url::of('https://speedat.test'), 'home'),
            year: 2026,
            scripts: PageView::CALCULATOR_SCRIPTS,
        );

        return $this->view()->render('site/home', ['view' => HomeView::build($page)]);
    }

    private function terms(Doc $doc): string
    {
        return $this->view()->render('site/terms', ['page' => $this->pageView($doc, '/terms', 'Terms and Conditions')]);
    }

    private function assertNoFreeClaim(string $html, string $where): void
    {
        foreach (['free pickup', 'free collection', 'free pick-up', 'free pick up'] as $claim) {
            $this->assertFalse(stripos($html, $claim) !== false, $where . ' still says "' . $claim . '"');
        }
    }

    /** @return array<string,string> */
    private static function chargedLines(): array
    {
        return [
            'separate'      => 'Pickup | 1000 | separate',
            'info'          => 'Collection | 800 | info',
            'on'            => 'Pickup and service charges | 500 | on',
            'beside other'  => "Delivery | 1000 | on\nPick-up | 1000 | separate",
        ];
    }

    /** @return array<string,string> */
    private static function freeLines(): array
    {
        return [
            // The live site's add-on: a delivery charge says nothing about pickup.
            'delivery on'   => 'Delivery | 1000 | on',
            // A tick box the customer chooses is not a charge for the pickup.
            'pickup off'    => 'Pickup | 1000 | off',
            // A separate 0 is dropped by the Engine: nothing is printed, nothing is charged.
            'separate zero' => 'Pickup | 0 | separate',
            'none'          => '',
        ];
    }

    public function testTheHelperReadsSeparateAndOnLinesByLabel(): void
    {
        foreach (self::chargedLines() as $case => $addons) {
            $this->assertTrue(LocalAreas::pickupCharged($this->withAddons($addons)->settings()), $case . ' should count as charged');
        }
        foreach (self::freeLines() as $case => $addons) {
            $this->assertFalse(LocalAreas::pickupCharged($this->withAddons($addons)->settings()), $case . ' should count as free');
        }
    }

    public function testChargedPickupIsNeverCalledFreeOnTheHomePageItsMetaOrItsJsonLd(): void
    {
        foreach (self::chargedLines() as $case => $addons) {
            $doc   = $this->withAddons($addons);
            $html  = $this->home($doc);
            $facts = $this->facts($doc);

            $this->assertNoFreeClaim($html, 'home (' . $case . ')');
            $this->assertNoFreeClaim($facts->siteDescription(), 'site description (' . $case . ')');

            // Still names the area: only the claim went.
            $this->assertStringContains(e(LocalAreas::PICKUP_SENTENCE_CHARGED), $html);
            $this->assertStringContains('Pickup across Lahore - DHA, Bahria Town', $facts->siteDescription());

            // The description the meta tags and the graph carry is the same
            // charged sentence, not a stale copy.
            $this->assertStringContains('content="' . e($facts->siteDescription()) . '"', $html);
            $graph = $this->jsonLd($html);
            $this->assertStringContains('Pickup across Lahore', $graph);
            $this->assertNoFreeClaim($graph, 'JSON-LD (' . $case . ')');
        }
    }

    public function testChargedPickupIsNeverCalledFreeInTheTerms(): void
    {
        foreach (self::chargedLines() as $case => $addons) {
            $html = $this->terms($this->withAddons($addons));

            $this->assertNoFreeClaim($html, 'terms (' . $case . ')');
            $this->assertStringContains('Pickup within Lahore is available; any pickup charge is shown with your', $html);
        }
    }

    public function testFreePickupKeepsTodaysExactWording(): void
    {
        foreach (self::freeLines() as $case => $addons) {
            $doc   = $this->withAddons($addons);
            $facts = $this->facts($doc);
            $home  = $this->home($doc);

            $this->assertSame(self::FREE_DESCRIPTION, $facts->siteDescription(), $case);
            $this->assertSame(LocalAreas::PICKUP_SENTENCE, LocalAreas::pickupSentence($doc->settings()), $case);
            $this->assertStringContains(e(LocalAreas::PICKUP_SENTENCE), $home);
            $this->assertStringContains('content="' . e(self::FREE_DESCRIPTION) . '"', $home);
            $this->assertStringContains('Free pickup across Lahore', $this->jsonLd($home));
            $this->assertStringContains('We offer free pickup within Lahore.', $this->terms($doc));
        }
    }

    /** Every ld+json block in the page, decoded and re-encoded without escaping, so a plain substring search works. */
    private function jsonLd(string $html): string
    {
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);
        $this->assertTrue(($m[1] ?? []) !== [], 'the page carries no JSON-LD');

        $out = '';
        foreach ($m[1] as $raw) {
            $out .= json_encode(json_decode($raw, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }
}
