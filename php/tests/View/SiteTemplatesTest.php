<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\View\AboutView;
use Speedat\View\ContactView;
use Speedat\View\Doc;
use Speedat\View\ErrorView;
use Speedat\View\FaqView;
use Speedat\View\HomeView;
use Speedat\View\PageView;
use Speedat\View\ServicesView;

/**
 * Every public template, rendered for real against a fixture document.
 *
 * Four things are checked on every page, because they are the four that a
 * careful reviewer stops catching after the tenth template: no value escapes
 * into live markup, there is exactly one h1, the shell is whole, and the page
 * renders at all (a template that throws is a 500 in production).
 */
final class SiteTemplatesTest extends ViewTestCase
{
    /** @return array<string,string> page name => rendered HTML, from the benign document */
    private function allPages(?Doc $doc = null): array
    {
        $doc = $doc ?? $this->doc();
        $v   = $this->view();

        return [
            'home'      => $v->render('site/home', ['view' => HomeView::build(
                $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS])
            )]),
            'services'  => $v->render('site/services', ['view' => ServicesView::build($this->pageView($doc, '/services', 'Services'))]),
            'about'     => $v->render('site/about', ['view' => AboutView::build($this->pageView($doc, '/about', 'About us'))]),
            'contact'   => $v->render('site/contact', ['view' => ContactView::build(
                $this->pageView($doc, '/contact', 'Contact'),
                csrf: 'csrf-token-value',
                formToken: 'form-token-value',
            )]),
            'faq'       => $v->render('site/faq', ['view' => FaqView::build($this->pageView($doc, '/faq', 'Questions'))]),
            'not_found' => $v->render('site/error', ['view' => ErrorView::notFound($this->pageView($doc, '/nope', 'Page not found'))]),
            'error'     => $v->render('site/error', ['view' => ErrorView::failed($this->pageView($doc, '/boom', 'Something went wrong'))]),
        ];
    }

    public function testEveryPageRendersTheWholeShell(): void
    {
        foreach ($this->allPages() as $name => $html) {
            $this->assertStringContains('<!doctype html>', $html, $name);
            $this->assertStringContains('<html lang="en" data-theme="light">', $html, $name);
            $this->assertStringContains('class="site-head"', $html, $name);
            $this->assertStringContains('<main>', $html, $name);
            $this->assertStringContains('class="site-foot"', $html, $name);
            $this->assertStringContains('aria-label="Pages"', $html, $name);
            $this->assertStringContains('</html>', $html, $name);
        }
    }

    public function testEveryPageHasExactlyOneH1(): void
    {
        foreach ($this->allPages() as $name => $html) {
            $this->assertOneH1($html, $name);
        }
    }

    /**
     * The whole point of the escaping rule, proved rather than asserted: a
     * document whose every string is a working XSS payload must render a page
     * with no live script, no live img and no broken attribute.
     */
    public function testNoValueEscapesIntoLiveMarkup(): void
    {
        foreach ($this->allPages($this->hostileDoc()) as $name => $html) {
            $this->assertNoInjection($html, $name);
            // ... and it really did print the value, escaped, rather than
            // dropping it - otherwise this test would pass on an empty page.
            $this->assertStringContains('&lt;script&gt;alert(1)', $html, $name . ': the hostile value was not printed at all');
        }
    }

    public function testTheHeadCarriesTitleDescriptionAndCanonicalWhenTheSeoSlotIsEmpty(): void
    {
        $pages = $this->allPages();

        $this->assertStringContains('<title>Services - Speedat International Courier</title>', $pages['services'], 'services');
        $this->assertStringContains('<meta name="description"', $pages['services'], 'services');
        $this->assertStringContains('property="og:title"', $pages['services'], 'services');
        $this->assertStringContains('name="twitter:card" content="summary_large_image"', $pages['services'], 'services');
        // The phone's chrome is the colour of the strip the page starts with, and that strip is the yellow
        // header in both themes (docs/brand.md). White here drew a seam above the bar.
        $this->assertStringContains('<meta name="theme-color" content="#FFCC00">', $pages['services'], 'services');
    }

    /** When the SEO track fills the slot, the fallback must get out of the way completely. */
    public function testTheSeoSlotReplacesTheFallbackHead(): void
    {
        $doc  = $this->doc();
        $page = $this->pageView($doc, '/faq', 'Questions', ['headHtml' => '<title>From the SEO track</title>']);
        $html = $this->view()->render('site/faq', ['view' => FaqView::build($page)]);

        $this->assertStringContains('<title>From the SEO track</title>', $html);
        $this->assertStringNotContains('property="og:title"', $html, 'the fallback head rendered as well as the slot');
    }

    /**
     * THE PRODUCTION HEAD. The other two paths are floors; this is the one a
     * controller uses - `Head::forKey(...)` handed to `PageView`, printed by
     * the SEO track's own `partials/seo`. It is asserted here because "the
     * layout calls the documented entry point" is a claim that is only true
     * while the two files agree, and nothing else in either suite renders the
     * pair together.
     */
    public function testTheSeoTracksHeadIsWhatTheLayoutPrintsWhenItIsGivenOne(): void
    {
        $doc   = $this->doc();
        $facts = new SiteFacts($doc->data(), $doc->version(), $doc->publishedAt());
        $seo   = Head::forKey($facts, Url::of('https://speedat.test'), 'faq');

        $page = new PageView(
            doc: $doc,
            path: '/faq',
            title: 'Questions',
            seo: $seo,
            year: 2026,
        );
        $html = $this->view()->render('site/faq', ['view' => FaqView::build($page)]);

        $this->assertSame('seo', $page->headSource(), 'a PageView carrying a Head must choose the SEO head');
        // Its title, its canonical, its graph - and exactly one of each.
        $this->assertStringContains('<title>' . $seo->title() . '</title>', $html);
        $this->assertStringContains('href="' . $seo->canonical() . '"', $html);
        $this->assertSame(1, substr_count($html, '<title>'), 'two titles is worse than none');
        $this->assertSame(1, substr_count($html, 'application/ld+json'), 'the fallback graph rendered as well as the real one');
        $this->assertStringContains('"@type":"FAQPage"', $html, 'the FAQ page must carry the FAQPage node');
        // And the fallback head is nowhere: its robots tag is the tell, because
        // the SEO track does not print that one.
        $this->assertStringNotContains('<meta name="robots" content="index, follow">', $html);
    }

    public function testTheDarkThemeIsRenderedByTheServerSoThereIsNoFlash(): void
    {
        $doc  = $this->doc();
        $page = $this->pageView($doc, '/faq', 'Questions', ['theme' => PageView::THEME_DARK]);
        $html = $this->view()->render('site/faq', ['view' => FaqView::build($page)]);

        $this->assertStringContains('<html lang="en" data-theme="dark">', $html);
        // The bar is the brand and does not invert, so neither does the phone chrome above it.
        $this->assertStringContains('<meta name="theme-color" content="#FFCC00">', $html);
        // The toggle names what a tap DOES, so in dark mode it offers light.
        $this->assertStringContains('class="btn outline theme" aria-label="Light mode"', $html);
        $this->assertStringContains('#i-sun', $html);
    }

    public function testTheCurrentPageIsMarkedInTheNav(): void
    {
        $html = $this->allPages()['services'];

        $this->assertStringContains('href="/services" aria-current="page"', $html);
        $this->assertStringNotContains('href="/faq" aria-current="page"', $html);
    }

    public function testTheFaqAnswersAreOpenOnThePage(): void
    {
        $html = $this->allPages()['faq'];

        $this->assertStringContains('<dl class="faq-list">', $html);
        $this->assertStringContains('<dt>How long does it take?</dt>', $html);
        $this->assertStringContains('<dd>Three to ten working days.</dd>', $html);
        // Nothing folds: no accordion, no <details>, here or in the Next app.
        // The one <details> on the page is the header's phone menu
        // (site/partials/header.php), so the check is on the main content.
        $start = strpos($html, '<main');
        $end   = strpos($html, '</main>');
        $this->assertTrue(is_int($start) && is_int($end), 'the page has a <main>');
        $main = substr($html, (int) $start, (int) $end - (int) $start);
        $this->assertStringContains('<dl class="faq-list">', $main);
        $this->assertStringNotContains('<details', $main);
        $this->assertSame(1, substr_count($html, '<details'), 'only the header menu folds');
        $this->assertStringContains('<details class="menu">', $html);
    }

    public function testContactStatesEachNumberOnceAndCarriesTheFormsGuards(): void
    {
        $html = $this->allPages()['contact'];

        $this->assertStringContains('class="reach-big"', $html);
        $this->assertStringContains('name="_token" value="csrf-token-value"', $html);
        $this->assertStringContains('name="t" value="form-token-value"', $html);
        $this->assertStringContains('name="website"', $html, 'the honeypot is missing');
        $this->assertStringContains('method="post"', $html);
        // The contact line under the form goes without its numbers line.
        $this->assertStringNotContains('class="reach-line"', $html);
        // The landline is stated once, in the Call row; phone2's copy of the
        // mobile is kept and the numbers already printed above are dropped.
        $this->assertStringContains('<dt>Call</dt>', $html);
        $this->assertStringContains('<dt>Also</dt>', $html);
    }

    public function testAContactFormRejectionKeepsWhatWasTypedAndSaysWhy(): void
    {
        $doc  = $this->doc();
        $view = ContactView::build(
            $this->pageView($doc, '/contact', 'Contact'),
            csrf: 'c',
            formToken: 't',
            values: ['name' => 'Ayesha', 'message' => 'Two boxes to Canada', 'destId' => 'ca'],
            errors: ['phone' => 'Add a number we can reach you on.'],
        );
        $html = $this->view()->render('site/contact', ['view' => $view]);

        $this->assertStringContains('value="Ayesha"', $html);
        $this->assertStringContains('>Two boxes to Canada</textarea>', $html);
        $this->assertStringContains('value="ca" selected', $html);
        $this->assertStringContains('Add a number we can reach you on.', $html);
    }

    public function testTheSentStateReplacesTheFormEntirely(): void
    {
        $doc  = $this->doc();
        $view = ContactView::build($this->pageView($doc, '/contact', 'Contact'), sent: true);
        $html = $this->view()->render('site/contact', ['view' => $view]);

        $this->assertStringContains('class="card contact-done"', $html);
        $this->assertStringNotContains('<form', $html);
    }

    public function testTheErrorPagesWearTheSiteLayoutAndSayNothingAboutWhatBroke(): void
    {
        $pages = $this->allPages();

        foreach (['not_found' => 'Nothing is at this address.', 'error' => 'This page did not load.'] as $name => $line) {
            $this->assertStringContains('class="site-head"', $pages[$name], $name);
            $this->assertStringContains($line, $pages[$name], $name);
            $this->assertStringContains('Get a price</a>', $pages[$name], $name);
            $this->assertStringContains('WhatsApp us', $pages[$name], $name);
        }

        // No "Try again": in PHP there is nothing to reset, and a button that
        // does nothing is worse than no button.
        $this->assertStringNotContains('Try again', $pages['error']);
    }

    public function testServicesPrintsTheOwnersLinesAndTheComputedFacts(): void
    {
        $html = $this->allPages()['services'];

        $this->assertStringContains('<ul class="svc-list">', $html);
        // "plane | Express | ..." - the icon token is parsed and dropped.
        $this->assertStringContains('<strong>Express</strong>', $html);
        $this->assertStringNotContains('>plane<', $html);
        // A line without a known icon token reads as "Title | text".
        $this->assertStringContains('<strong>Economy</strong>', $html);
        $this->assertStringContains('Pickup in Lahore.', $html);
        $this->assertStringContains('Book before 3 pm for same-day pickup.', $html);
        $this->assertStringContains('Parcels up to 25 kg priced on the <a href="/">home page</a>; heavier by quote.', $html);

        // "Documents up to 0.5 kg." was one of the computed facts. It went with
        // the document rate on 2026-09-27 (owner): there is no weight at which a
        // different price starts, so there is no fact left to state.
        $this->assertStringNotContains('Documents up to', $html);
    }

    public function testAboutDoesNotRepeatThePhoneNumbersItsFooterAlreadyPrints(): void
    {
        $html = $this->allPages()['about'];

        $this->assertStringContains('<h1>About Speedat International Courier</h1>', $html);
        $this->assertStringContains('<dt>Office</dt>', $html);
        $this->assertStringContains('Google Maps', $html);
        $this->assertStringNotContains('<dt>Call</dt>', $html);
        $this->assertStringNotContains('<dt>Email</dt>', $html);
        // "Honesty." - the value's name becomes the start of its sentence.
        $this->assertStringContains('<strong>Honesty.</strong> We quote the price we charge.', $html);
        // The hours are the row just above the contact line, so the line goes without them.
        $this->assertStringContains('class="reach-line"', $html);
        $this->assertStringNotContains('· Mon to Sat, 10 am to 7 pm</p>', $html);
    }

    /** A document with nothing filled in must still render every page, not fatal on a missing key. */
    public function testAnEmptyDocumentStillRendersEveryPage(): void
    {
        $doc = Doc::fromArray([]);

        foreach ($this->allPages($doc) as $name => $html) {
            $this->assertStringContains('</html>', $html, $name);
            $this->assertOneH1($html, $name);
        }
    }
}
