<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

/**
 * The offer popup's button link and four public-stylesheet rules that a
 * reader cannot see the reason for (fixed 2026-10-25).
 *
 *   - A BARE DOMAIN IS A LINK. The admin hint told the owner a wa.me link
 *     works; typed as `wa.me/923...` the allow-list dropped the button and the
 *     offer went live with nothing to tap and no warning. A bare dotted host
 *     is now read as https. A `javascript:` URL still drops the button.
 *   - THE CARD CLEARS THE STACKED BAR UNDER 360. There the live bar is 129px,
 *     not 73, and the card sat on top of the price.
 *   - THE COUNTRY PICKER IS 16PX. iOS Safari zooms into a smaller control.
 *   - A REVIEW AND AN FAQ ANSWER KEEP THEIR LINE BREAKS.
 *
 * The CSS checks are source assertions, for the same reason SiteStylesheetTest
 * gives: the browser walk proves the geometry, this keeps the rule in place.
 */
final class B15PromoAndSiteCssTest extends ViewTestCase
{
    public function testABareWaMeLinkKeepsTheButtonAsHttps(): void
    {
        $html = $this->promo('wa.me/923001234567');

        $this->assertStringContains('<a class="promo-cta" href="https://wa.me/923001234567">Get a price</a>', $html);
    }

    public function testABareWwwDomainWithAQueryKeepsTheButton(): void
    {
        $html = $this->promo('www.example.com?ref=popup');

        $this->assertStringContains('href="https://www.example.com?ref=popup"', $html);
    }

    public function testTheAllowListStillWorksAsBefore(): void
    {
        $this->assertStringContains('href="https://wa.me/1"', $this->promo('https://wa.me/1'));
        $this->assertStringContains('href="/contact"', $this->promo('/contact'));
        $this->assertStringContains('href="mailto:hello@speedat.test"', $this->promo('mailto:hello@speedat.test'));

        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'contact', 'wa.me:80/x'] as $href) {
            $html = $this->promo($href);

            $this->assertStringContains('class="promo-pop"', $html, 'the card still shows for ' . $href);
            $this->assertStringNotContains('promo-cta', $html, 'no button for ' . $href);
        }
    }

    public function testTheAdminHintShowsAFullLink(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/partials/promo.php');

        $this->assertStringContains('https://wa.me/', $src, 'the hint shows the link in the form that is never dropped');
    }

    public function testTheCardIsLiftedClearOfTheStackedBarUnder360(): void
    {
        $css = $this->css('site.css');

        $this->assertSame(1, preg_match(
            '/@media \(max-width: 359\.98px\) \{\s*\.site\.wrap:has\(\.pricebar\) \.promo-pop:not\(\[hidden\]\),\s*'
            . '\.site\.wrap:has\(\.pricebar\) ~ \.promo-pop:not\(\[hidden\]\) \{\s*'
            . 'bottom: calc\(12px \+ var\(--tap-big, 56px\) \+ 73px \+ env\(safe-area-inset-bottom, 0px\)\);/',
            $css,
        ), 'the under-360 lift mirrors the stacked --i-bar');
    }

    public function testTheCountryPickerIsAtLeast16px(): void
    {
        $css = $this->css('calculator.css');

        $this->assertSame(1, preg_match('/\.instrument select\.ctile-more \{[^}]*?font-size:\s*16px;/s', $css), 'iOS zooms into a control under 16px');
    }

    public function testReviewsAndFaqAnswersKeepTheirLineBreaks(): void
    {
        $css = $this->css('site.css');

        $this->assertSame(1, preg_match('/\.review-text \{[^}]*?white-space:\s*pre-line;/s', $css));
        $this->assertSame(1, preg_match('/\.faq-list dd \{[^}]*?white-space:\s*pre-line;/s', $css));
    }

    private function promo(string $href): string
    {
        $doc = $this->doc(['promo' => [
            'enabled'  => true,
            'title'    => 'Eid offer',
            'message'  => 'Ask us on WhatsApp.',
            'ctaLabel' => 'Get a price',
            'ctaHref'  => $href,
        ]]);

        return $this->view()->partial('site/partials/promo', ['page' => $this->pageView($doc)]);
    }

    private function css(string $file): string
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/' . $file);

        $this->assertTrue($css !== false && $css !== '', $file . ' is readable');

        return (string) $css;
    }
}
