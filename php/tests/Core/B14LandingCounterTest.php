<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Domain\Analytics\PageViews;
use Speedat\Tests\TestCase;

/**
 * The landing counter's fixes of 2026-10-25: it counts a page somebody read
 * rather than a request somebody sent, an arrival rather than a click, and a
 * person reading in Telegram's in-app browser rather than throwing them away.
 *
 * NOTHING HERE TOUCHES THE DATABASE, on purpose and by construction: every
 * record() call below is one the cheap value checks refuse before a statement
 * is prepared, which is exactly the property being pinned - a click from one
 * of the site's own pages must cost no connection at all.
 *
 * The shutdown hook in Core\Bootstrap::web() that feeds countable() is not
 * reachable from inside a test process (it reads back http_response_code()
 * and headers_list(), both empty under the CLI); what it decides is
 * countable(), and that is tested here.
 */
final class B14LandingCounterTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    /* ---------------- only a page somebody read ---------------- */

    public function testA200HtmlGetIsALanding(): void
    {
        $this->assertTrue(PageViews::countable('GET', 200, 'text/html; charset=utf-8'));
        $this->assertTrue(PageViews::countable('get', 200, 'Text/HTML'), 'case is not a reason to drop a visit');
    }

    public function testRedirectsErrorsAndNonPagesAreNotLandings(): void
    {
        $this->assertFalse(PageViews::countable('GET', 301, 'text/html; charset=utf-8'), '/services/ -> /services was two landings');
        $this->assertFalse(PageViews::countable('GET', 303, ''), 'the redirect after a form post');
        $this->assertFalse(PageViews::countable('GET', 404, 'text/html; charset=utf-8'), 'a scanner probe or /FAQ');
        $this->assertFalse(PageViews::countable('GET', 500, 'text/html; charset=utf-8'));
        $this->assertFalse(PageViews::countable('GET', 200, 'application/json; charset=utf-8'), 'an API answer');
        $this->assertFalse(PageViews::countable('GET', 200, 'text/plain; charset=utf-8'), 'robots.txt');
        $this->assertFalse(PageViews::countable('GET', 200, 'application/xml'), 'the sitemap');
        $this->assertFalse(PageViews::countable('GET', 200, ''), 'no Content-Type is not a page');
    }

    public function testHeadAndPostAreNotLandings(): void
    {
        $this->assertFalse(PageViews::countable('HEAD', 200, 'text/html; charset=utf-8'), 'a monitor asking whether the page exists');
        $this->assertFalse(PageViews::countable('POST', 200, 'text/html; charset=utf-8'), 'a form re-shown with its errors');
    }

    /* ---------------- an arrival, not a click ---------------- */

    public function testAClickFromOneOfOurOwnPagesIsNotCounted(): void
    {
        $views = new PageViews();
        $own   = ['https://speedat.co', 'http://speedat.co'];

        $this->assertFalse($views->record('/services', self::BROWSER, 'https://speedat.co/', $own));
        $this->assertFalse($views->record('/contact', self::BROWSER, 'https://www.speedat.co/services?x=1', $own), 'www is the same site');
        $this->assertFalse($views->record('/contact', self::BROWSER, 'https://SPEEDAT.CO/contact', $own), 'case is the same site');
    }

    public function testTheHostTheRequestArrivedOnCountsAsOursToo(): void
    {
        $views = new PageViews();

        // app.url empty, as on a fresh install: the request's own origin is
        // still enough, port and all.
        $this->assertFalse($views->record('/faq', self::BROWSER, 'http://127.0.0.1:8081/', ['', 'http://127.0.0.1:8081']));
        $this->assertFalse($views->record('/faq', self::BROWSER, 'https://speedat.co/', ['speedat.co']), 'a bare host is accepted');
    }

    /* ---------------- Telegram ---------------- */

    public function testTelegramsInAppBrowserIsAPerson(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-A546E Build/TP1A.220624.014; wv) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.146 Mobile Safari/537.36 Telegram-Android/11.2.3 '
            . '(Samsung SM-A546E; Android 13; SDK 33; HIGH)';

        $this->assertFalse(PageViews::isBot($ua), 'a real visit was being thrown away');
        $this->assertSame(PageViews::PHONE, PageViews::device($ua));
    }

    public function testTelegramsPreviewFetcherIsStillABot(): void
    {
        $this->assertTrue(PageViews::isBot('TelegramBot (like TwitterBot)'));
        $this->assertTrue(PageViews::isBot('WhatsApp/2.23.20.0 A'), 'the other preview fetcher is unchanged');
    }
}
