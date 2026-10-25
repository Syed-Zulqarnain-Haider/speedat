<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Response;
use Speedat\View\AboutView;
use Speedat\View\ContactView;
use Speedat\View\FaqView;
use Speedat\View\HomeView;
use Speedat\View\PageView;
use Speedat\View\ServicesView;

/**
 * The five customer pages.
 *
 * Every method here is four lines: build the shell (`Page`), build the page's
 * own view model, render its template. Nothing is decided in this file that
 * `Domain` or `View` has not already decided - a controller that starts
 * computing is a controller that will disagree with the tests that cover the
 * view models.
 *
 * WHY NO CACHING HEADER. `App::page()` marks every HTML response `no-store`.
 * These pages carry prices from a document the owner can republish at any
 * moment, and the price hold can come on between two requests; a shared cache
 * holding the page for even a minute is a visitor quoted a price the owner has
 * already withdrawn. The assets, which are 99% of the bytes, are cached for a
 * week by `.htaccess` with a `filemtime()` query string - that is where the
 * performance comes from, not from caching the HTML.
 */
final class SiteController
{
    /**
     * GET / - the quote page.
     *
     * Two shapes, decided by the hold and nowhere else:
     *
     *   prices on   the calculator, its three scripts, and the country tiles'
     *               "from" prices rendered by PHP
     *   prices held the by-hand WhatsApp form, two scripts, and NO PRICE
     *               ANYWHERE IN THE HTML - `HomeView::build()` builds a
     *               `HoldData` instead of a `CalculatorData`, so the rate
     *               document never reaches the page
     */
    public function home(App $app): Response
    {
        $held = Page::doc()->holdOn();

        $page = Page::forKey($app, 'home', $held ? Page::HOLD_SCRIPTS : PageView::CALCULATOR_SCRIPTS);

        return $app->page('site/home', ['view' => HomeView::build($page, $app->request->basePath)]);
    }

    /** GET /services */
    public function services(App $app): Response
    {
        return $app->page('site/services', ['view' => ServicesView::build(Page::forKey($app, 'services'))]);
    }

    /** GET /about */
    public function about(App $app): Response
    {
        return $app->page('site/about', ['view' => AboutView::build(Page::forKey($app, 'about'))]);
    }

    /**
     * GET /contact.
     *
     * The form is a real `<form method="post">`, so this is where its two
     * guards are minted: the per-session CSRF token and the signed render
     * timestamp `FormToken` checks on the way back. The honeypot needs nothing
     * from the server.
     *
     * `Flash::take()` is what makes the thank-you state a GET: the POST stored
     * the lead, redirected, and left a one-shot cookie behind. Reading it also
     * clears it, so a reload shows the form again rather than a thank-you for a
     * message that is not being sent - and nothing is stored twice.
     */
    public function contact(App $app): Response
    {
        $sent = Flash::take($app, 'contact_sent');

        return $app->page('site/contact', [
            'view' => ContactView::build(
                Page::forKey($app, 'contact'),
                csrf: $app->csrf()->token(),
                formToken: FormToken::issue(),
                sent: $sent,
            ),
        ]);
    }

    /** GET /faq */
    public function faq(App $app): Response
    {
        return $app->page('site/faq', ['view' => FaqView::build(Page::forKey($app, 'faq'))]);
    }

    /**
     * GET /terms - the terms and conditions.
     *
     * Not one of the five product pages and not in `Domain\Seo\Routes`, so it
     * takes the layout's fallback head rather than a hand-built one and never
     * reaches the sitemap: a draft the owner is still reviewing is linked from
     * the footer, not announced to a crawler. The shell is built directly here
     * because the page needs no view model of its own - the legal copy is static
     * and the only live values (the contact details) come straight off the
     * document the footer already reads.
     *
     * The nav path is "/terms", which matches none of the five links, so no nav
     * item is marked current - correct, because Terms is not a nav destination.
     */
    public function terms(App $app): Response
    {
        $page = new PageView(
            doc: Page::doc(),
            path: '/terms',
            title: 'Terms and Conditions',
            theme: PageView::themeFromCookie($app->request->cookie('theme')),
            year: (int) Clock::now()->format('Y'),
            scripts: ['js/ui.js'],
        );

        return $app->page('site/terms', ['page' => $page]);
    }

    /**
     * The contact page re-rendered after a rejected post: the same page, with
     * what was typed still in it and the reason above the fields.
     *
     * It is here rather than in `ContactController` so that "what /contact
     * looks like" has one owner. A fresh render token is issued with it - the
     * old one has been spent, and without a new one the second attempt would be
     * refused for a different reason than the first.
     *
     * WHEN THE TOKEN THAT CAME IN WAS GOOD, THE NEW ONE IS BACKDATED (fixed
     * 2026-10-25). A customer told "Enter a phone number we can call" fixes
     * one dot and presses Send again inside three seconds - and a token minted
     * "now" refused that as "That was quick", and again on every quick retry,
     * for a person who had already proved they were not a script by waiting
     * out the first token. So a rejection for the fields or the rate limit
     * (`$tokenPassed`) hands back a token already MIN_AGE_MS old: the next
     * submit is judged on its fields alone. A rejection BECAUSE of the token -
     * too fast, stale, forged - still gets one stamped now, so the three
     * seconds are never skipped by the one sender they exist to slow down.
     * The 24-hour window is counted from the backdated stamp, which moves it
     * by three seconds and nothing more.
     *
     * @param array<string,string> $values what the visitor typed
     * @param array<string,string> $errors field name, or "_" for the whole form
     */
    public function contactRejected(App $app, array $values, array $errors, int $status, bool $tokenPassed = false): Response
    {
        return $app->page('site/contact', [
            'view' => ContactView::build(
                Page::forKey($app, 'contact'),
                csrf: $app->csrf()->token(),
                formToken: $tokenPassed
                    ? FormToken::issue(Clock::millis() - FormToken::MIN_AGE_MS)
                    : FormToken::issue(),
                values: $values,
                errors: $errors,
            ),
        ], $status);
    }

    /**
     * The thank-you card without a redirect, for the honeypot.
     *
     * A bot that filled the hidden field is told the message was sent and
     * nothing is stored (the port's rule, from actions.ts). It gets no flash
     * cookie and no redirect: there is nothing to protect from a double submit
     * because there was no submit.
     */
    public function contactPretendSent(App $app): Response
    {
        return $app->page('site/contact', [
            'view' => ContactView::build(Page::forKey($app, 'contact'), sent: true),
        ]);
    }
}
