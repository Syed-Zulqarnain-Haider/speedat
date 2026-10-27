<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\App;
use Speedat\Core\Request;
use Speedat\Core\Response;
use Speedat\Core\Router;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\Sitemap;
use Speedat\Http\Kernel;
use Speedat\View\ErrorView;

/**
 * What the front controller does before and after a route: the canonical URL,
 * the generated documents, and the two error pages.
 *
 * ONE URL PER PAGE is an SEO rule with teeth (docs/seo.md, "The URL policy the
 * routing layer must enforce"): `/faq/`, `//faq` and `/./faq` are the same page
 * as `/faq`, and a site that serves all four teaches a search engine that it
 * has four pages with identical content.
 *
 * THE ERROR PAGES have to carry the real status code. A 404 answering 200 is how
 * a mistyped URL gets indexed; a 500 answering 200 is how an outage goes
 * unnoticed. And neither may say a word about what went wrong.
 */
final class RoutingTest extends HttpTestCase
{
    /* ===================================================================== */
    /*  THE CANONICAL URL                                                    */
    /* ===================================================================== */

    public function testEveryOtherFormOfAPathRedirectsToTheCanonicalOne(): void
    {
        $forms = [
            '/faq/'        => '/faq',
            '/faq//'       => '/faq',
            '//faq'        => '/faq',
            '/./faq'       => '/faq',
            '/services/'   => '/services',
            '/a/../faq'    => '/faq',
            '/index.php'   => '/',
        ];

        foreach ($forms as $asked => $canonical) {
            $answer = $this->get($asked);

            $this->assertStatus(301, $answer, $asked);
            $this->assertSame($canonical, $answer->header('Location'), $asked);
            $this->assertSame('', $answer->body(), $asked . ': a redirect carries no page');
        }
    }

    /** The old static site's pages, still asked for by search engines and old links. */
    public function testTheOldHtmlPagesRedirectToThePagesThatReplacedThem(): void
    {
        $old = [
            '/index.html'          => '/',
            '/services.html'       => '/services',
            '/about.html'          => '/about',
            '/tracking.html'       => '/track',
            '/privacy-policy.html' => '/terms',
        ];

        foreach ($old as $asked => $now) {
            $answer = $this->get($asked);

            $this->assertStatus(301, $answer, $asked);
            $this->assertSame($now, $answer->header('Location'), $asked);
        }

        $this->assertStatus(404, $this->get('/never-existed.html'), 'an unknown .html is still not found');
    }

    public function testTheCanonicalFormIsServedAndNotRedirected(): void
    {
        foreach (['/', '/services', '/about', '/contact', '/faq'] as $path) {
            $this->assertStatus(200, $this->get($path), $path);
        }
    }

    /**
     * `/admin/rates` WAS THE ONE PATH IN ITS OWN TREE WITH NOTHING AT IT.
     *
     * Save, publish, review, export and six more all live under
     * `/admin/rates/`, and the editor they belong to is at `/admin`. So an owner
     * who trimmed a URL back, or guessed one from a bookmark, met the not-found
     * page at the single address in that tree that looks most like a page, while
     * everything under it worked.
     *
     * Checked here rather than in the admin suite on purpose: it has to answer
     * the same way to anybody, signed in or not. A redirect that only appeared
     * for a signed-in admin would be one more thing telling a stranger which
     * sessions exist.
     */
    public function testTheRatesFolderRedirectsToTheEditorForAnybody(): void
    {
        $answer = $this->get('/admin/rates');

        $this->assertStatus(301, $answer, '/admin/rates is still a 404');
        $this->assertSame('/admin', $answer->header('Location'), 'it does not point at the editor');
        $this->assertSame('', $answer->body(), 'a redirect carries no page');
    }

    /**
     * A BODY PHP THREW AWAY IS A 400, NOT A PAGE.
     *
     * Past `post_max_size` PHP discards the whole request body before the
     * application is reached: `$_POST` is empty, `$_FILES` is empty and no error
     * code is raised, so the only trace left is the Content-Length the client
     * announced. A twelve-megabyte post used to spend three and a half seconds
     * on the wire and be answered with a page, where the quality bar says an
     * input cap is a 400. `deploy/htaccess` should be refusing it before PHP is
     * started at all; this is the floor under that, not the plan.
     */
    public function testAPostWhoseBodyWasDiscardedIsABadRequest(): void
    {
        $answer = $this->send(Request::synthetic('POST', '/contact', [], [], [
            'content-type'   => 'application/x-www-form-urlencoded',
            'content-length' => '12582912',
        ]));

        $this->assertStatus(400, $answer, 'a discarded body was not a bad request');
        $this->assertStringContains('too large to send in one request', $answer->body(), 'the visitor is not told what happened');
    }

    /** A query string survives the redirect: the parameters belong to the page. */
    public function testARedirectKeepsTheQueryString(): void
    {
        $answer = $this->get('/faq/', ['ref' => 'whatsapp share']);

        $this->assertStatus(301, $answer);
        $this->assertSame('/faq?ref=whatsapp+share', $answer->header('Location'));
    }

    /**
     * A POST is NEVER redirected.
     *
     * Every browser follows a 301 with a GET, so redirecting a submitted form
     * would silently turn the message into a page view and lose it.
     * `Core\Router` normalises the path it matches on, so the handler is reached
     * anyway - and the proof it was reached is that the missing CSRF token is
     * what refuses the request, not the router.
     */
    public function testAPostToATrailingSlashReachesTheHandlerRatherThanARedirect(): void
    {
        $answer = $this->post('/contact/', ['name' => 'Someone']);

        $this->assertStatus(403, $answer, 'a POST was redirected instead of being handled');
        $this->assertSame('', $answer->header('Location'));
    }

    /* ===================================================================== */
    /*  404 AND 405                                                          */
    /* ===================================================================== */

    /**
     * The designed 404: the site's own layout, the real status, two ways out,
     * and not one word about the address that produced it.
     */
    public function testAnUnknownUrlIsADesigned404(): void
    {
        $answer = $this->get('/no-such-page-anywhere');

        $this->assertStatus(404, $answer);
        $this->assertContentType('text/html; charset=utf-8', $answer);
        $this->assertStringContains('no-store', $answer->header('Cache-Control'));

        $html = $answer->body();
        $this->assertStringContains(ErrorView::NOT_FOUND_TITLE, $html);
        $this->assertStringContains(ErrorView::NOT_FOUND_LINE, $html);
        // The site's own shell, not a bare document.
        $this->assertStringContains('class="site-head"', $html);
        $this->assertStringContains('class="site-foot"', $html);
        $this->assertOneH1($answer);
        // Two ways out.
        $this->assertStringContains('Get a price', $html);
        $this->assertStringContains('wa.me/', $html);
    }

    /** A crawler is told plainly not to index it, and under no canonical. */
    public function testThe404IsNotIndexableAndCarriesNoCanonical(): void
    {
        $html = $this->get('/no-such-page-anywhere')->body();

        $this->assertStringContains('content="noindex, nofollow"', $html);
        $this->assertStringNotContains('rel="canonical"', $html, 'an error page claimed a canonical URL');
        $this->assertStringNotContains('property="og:url"', $html, 'an error page reflected a URL into the head');
    }

    /**
     * The requested path is not echoed back anywhere - not in the copy, not in
     * the nav, not in a canonical. A `Location`-style reflection on an error
     * page is a reflected-content surface for no benefit at all.
     */
    public function testTheRequestedPathIsNeverReflectedIntoThe404(): void
    {
        $answer = $this->get('/zzz-unique-marker-42');

        $this->assertStatus(404, $answer);
        $this->assertStringNotContains('zzz-unique-marker-42', $answer->body());
    }

    public function testTheWrongMethodIs405WithAnAllowHeader(): void
    {
        $answer = $this->post('/faq');

        $this->assertStatus(405, $answer);
        $this->assertSame('GET', $answer->header('Allow'));
        $this->assertStringContains(ErrorView::ERROR_TITLE, $answer->body());
    }

    /** No error page, on any status, says anything about the inside of the server. */
    public function testNoErrorPageLeaksAnythingAboutTheServer(): void
    {
        foreach (['/no-such-page' => 404, '/faq' => 405] as $path => $status) {
            $answer = $status === 405 ? $this->post($path) : $this->get($path);
            $this->assertStatus($status, $answer, $path);

            foreach (['Exception', 'Stack trace', 'SQLSTATE', 'app/Http', 'app/Domain', '.php:', 'D:\\'] as $leak) {
                $this->assertStringNotContains($leak, $answer->body(), $path . ': leaked "' . $leak . '"');
            }
        }
    }

    /* ===================================================================== */
    /*  THE 500                                                              */
    /* ===================================================================== */

    /**
     * A handler that throws becomes the designed 500 - the real status, the
     * site's own layout, and not one word of the exception.
     *
     * The exception message here deliberately contains a file path and a driver
     * phrase, because those are exactly what a visitor must never be shown and
     * what an unguarded error page prints first.
     */
    public function testAThrowingHandlerBecomesADesigned500ThatSaysNothing(): void
    {
        $answer = $this->through('/boom', static function (): Response {
            throw new \RuntimeException('SQLSTATE[HY000] cannot open /home/u123/domains/speedat/app/secret.php');
        });

        $this->assertStatus(500, $answer);
        $this->assertContentType('text/html; charset=utf-8', $answer);
        $this->assertStringContains('no-store', $answer->header('Cache-Control'));

        $html = $answer->body();
        $this->assertStringContains(ErrorView::ERROR_TITLE, $html);
        $this->assertStringContains(ErrorView::ERROR_LINE, $html);
        $this->assertStringContains('class="site-head"', $html, 'the 500 is not wearing the site');
        $this->assertOneH1($answer);
        // Two ways out, and no dead "try again" control - there is nothing to
        // reset in PHP; asking for the page again is how you try again.
        $this->assertStringContains('wa.me/', $html);

        foreach (['SQLSTATE', 'secret.php', '/home/u123', 'RuntimeException', 'Stack trace'] as $leak) {
            $this->assertStringNotContains($leak, $html, 'the 500 leaked "' . $leak . '"');
        }
    }

    /** A handler that forgets to return a Response is a bug, and a 500, not a blank page. */
    public function testAHandlerThatReturnsNothingIsA500(): void
    {
        $answer = $this->through('/oops', static fn (): string => 'not a response');

        $this->assertStatus(500, $answer);
        $this->assertStringContains(ErrorView::ERROR_TITLE, $answer->body());
    }

    /** One request through a router built for this test alone. */
    private function through(string $path, \Closure $handler): Answer
    {
        $router = new Router();
        $router->get($path, $handler);

        $app = new App(Request::synthetic('GET', $path));

        return new Answer(Kernel::handle($app, $router), $app);
    }

    /**
     * A machine endpoint answers in the envelope even when the client asked for
     * HTML, because a client handed a rendered page has to parse HTML to find
     * out what happened.
     */
    public function testAnApiPathGetsTheJsonEnvelopeEvenForABrowser(): void
    {
        $answer = $this->get('/api/nothing-here', [], ['accept' => 'text/html,application/xhtml+xml']);

        $this->assertStatus(404, $answer);
        $this->assertContentType('application/json; charset=utf-8', $answer);
        $this->assertSame('not_found', $answer->json()['error']['code'] ?? null);
        $this->assertTrue(is_string($answer->json()['error']['message'] ?? null));
    }

    /** The prefix that decision is made on has to be one the SEO layer hides. */
    public function testTheApiPrefixIsOneTheRobotsFileDisallows(): void
    {
        $this->assertTrue(in_array('/api', Routes::DISALLOWED, true));
    }

    /* ===================================================================== */
    /*  THE GENERATED DOCUMENTS                                              */
    /* ===================================================================== */

    public function testTheSitemapIsServedAsXmlAndParses(): void
    {
        $answer = $this->get('/sitemap.xml');

        $this->assertStatus(200, $answer);
        $this->assertContentType(Sitemap::CONTENT_TYPE, $answer);
        $this->assertStringContains('public, max-age=', $answer->header('Cache-Control'));

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($answer->body());
        libxml_use_internal_errors($previous);

        $this->assertTrue($xml !== false, 'the sitemap is not well-formed XML');

        $locations = [];
        foreach ($xml === false ? [] : $xml->url as $url) {
            $locations[] = (string) $url->loc;
        }

        $this->assertSame(count(Routes::all()), count($locations), 'one entry per public route');
        foreach ($locations as $loc) {
            $this->assertTrue(str_starts_with($loc, 'http'), 'a sitemap URL is not absolute: ' . $loc);
        }
    }

    /** Nothing behind a login and nothing a machine talks to is ever listed. */
    public function testTheSitemapListsNoAdminOrApiPath(): void
    {
        $body = $this->get('/sitemap.xml')->body();

        foreach (Routes::DISALLOWED as $prefix) {
            $this->assertStringNotContains($prefix, $body, 'the sitemap lists ' . $prefix);
        }
    }

    public function testRobotsTxtDisallowsWhatTheSitemapOmitsAndPointsAtIt(): void
    {
        $answer = $this->get('/robots.txt');

        $this->assertStatus(200, $answer);
        $this->assertContentType('text/plain; charset=UTF-8', $answer);

        $text = $answer->body();
        $this->assertStringContains('User-agent: *', $text);

        foreach (Routes::DISALLOWED as $prefix) {
            $this->assertStringContains('Disallow: ' . $prefix, $text);
        }

        $this->assertStringContains('Sitemap: http', $text);
        $this->assertStringContains('/sitemap.xml', $text);
    }

    /**
     * On a laptop or a staging box both documents carry `noindex`, so a preview
     * host that a crawler finds cannot teach it the wrong site. `app.url` is a
     * development address in this suite, which is why this is assertable here.
     */
    public function testADevelopmentBaseIsServedButNotIndexable(): void
    {
        foreach (['/sitemap.xml', '/robots.txt'] as $path) {
            $answer = $this->get($path);

            $this->assertStatus(200, $answer, $path);
            $this->assertSame('noindex', $answer->header('X-Robots-Tag'), $path);
        }
    }
}
