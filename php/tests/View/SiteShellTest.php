<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\View\ContactView;
use Speedat\View\HomeView;
use Speedat\View\PageView;

/**
 * Three things about the shell and the forms inside it that are true only
 * because of one attribute each, and that nothing else in the suite would
 * notice losing.
 *
 *   - THE FONT PRELOAD MUST NAME THE URL THE STYLESHEET WILL ASK FOR. It went
 *     through asset(), which appends `?v=<filemtime>`; fonts.css asks for the
 *     bare path, so the browser fetched both, warned that the preloaded copy
 *     was never used, and the preload cost a download instead of saving one.
 *   - A PLACEHOLDER <option> NEEDS `selected` AS WELL AS `disabled`. Without
 *     it the browser's own rule - select the first option that is not disabled
 *     - hands the control to the first country, so a select nobody has touched
 *     reads "Australia".
 *   - THE HONEYPOT MUST NOT BE ANNOUNCED. It is off-screen, which hides it
 *     from eyes and not from a screen reader; `aria-hidden` on its wrapper is
 *     what keeps a blind visitor from being asked for a website, and
 *     `tabindex="-1"` is what keeps a keyboard out of the trap.
 */
final class SiteShellTest extends ViewTestCase
{
    private function home(): string
    {
        $doc = $this->doc();

        return $this->view()->render('site/home', ['view' => HomeView::build(
            $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS])
        )]);
    }

    private function script(string $file): string
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/' . $file);

        $this->assertTrue($js !== false && $js !== '', $file . ' is readable');

        return (string) $js;
    }

    /**
     * EVERY PRELOADED FONT IS FETCHED ONCE.
     *
     * The proof is that the URL in the `<link rel=preload>` is character for
     * character the URL fonts.css resolves to: same path, no query string. A
     * preload that misses is worse than no preload at all.
     */
    public function testEveryPreloadedFontNamesTheUrlTheStylesheetAsksFor(): void
    {
        $html  = $this->home();
        $fonts = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/fonts.css');
        $this->assertTrue($fonts !== false && $fonts !== '', 'fonts.css is readable');

        $found = preg_match_all('/<link rel="preload"[^>]*href="([^"]+)"/', $html, $matches);
        $this->assertTrue($found > 0, 'the layout preloads the faces on the first-paint path');

        foreach ($matches[1] as $href) {
            $this->assertTrue(
                !str_contains($href, '?'),
                'the preload carries a cache-busting query the stylesheet does not: ' . $href
            );
            $this->assertStringContains(
                'url(../fonts/' . basename($href) . ')',
                (string) $fonts,
                'nothing in fonts.css asks for ' . $href . ', so the preload is a download nobody uses'
            );
        }

        // And the attributes that let the preload be matched at all: a font
        // fetched without `crossorigin` is fetched a second time by the CSS.
        foreach ($matches[0] as $tag) {
            $this->assertStringContains('as="font"', $tag);
            $this->assertStringContains('crossorigin', $tag);
        }
    }

    /**
     * THE PLACEHOLDER IS THE CHOSEN OPTION UNTIL A VISITOR CHOOSES.
     *
     * Server-rendered, so it holds with JavaScript off - which is the state
     * the hold panel's form is most likely to be read in.
     */
    public function testAPlaceholderCountryIsSelectedAsWellAsDisabled(): void
    {
        $doc = $this->doc();

        $held = $this->view()->render('site/home', ['view' => HomeView::build(
            $this->pageView(
                $this->doc([], ['on' => true, 'message' => 'Prices are being updated.']),
                '/',
                'Get a price',
                ['scripts' => PageView::CALCULATOR_SCRIPTS]
            )
        )]);

        $options = $this->view()->render('site/partials/dest-options', ['doc' => $doc, 'placeholder' => 'More countries']);

        foreach (['the hold panel' => $held, 'the destination list' => $options] as $where => $markup) {
            $found = preg_match('/<option value=""([^>]*)>/', $markup, $m);
            $this->assertSame(1, $found, $where . ' opens with a placeholder option');
            $this->assertStringContains('disabled', $m[1], $where . ': the placeholder cannot be submitted');
            $this->assertStringContains(
                'selected',
                $m[1],
                $where . ': without `selected` the browser chooses the first country instead'
            );
        }
    }

    /**
     * The same pair in the script that REPLACES that markup on mount. This is
     * the one the owner saw: the hold panel's enquiry form opened reading
     * "Australia", because the option it builds was disabled but not selected.
     */
    public function testEveryPlaceholderOptionTheScriptBuildsIsSelectedToo(): void
    {
        $js = $this->script('calculator.js');

        $found = preg_match_all('/h\("option",\s*\{([^}]*)\}/s', $js, $matches);
        $this->assertTrue($found > 0, 'calculator.js builds <option> elements');

        $bare = [];
        foreach ($matches[1] as $attributes) {
            if (str_contains($attributes, 'disabled: true') && !str_contains($attributes, 'selected: true')) {
                $bare[] = trim(preg_replace('/\s+/', ' ', $attributes) ?? '');
            }
        }

        $this->assertSame([], $bare, 'a disabled placeholder option is built without `selected`: ' . implode(' | ', $bare));
    }

    /**
     * THE HONEYPOT IS INVISIBLE TO EYES AND TO SCREEN READERS, AND UNREACHABLE
     * BY KEYBOARD - and it is still a plain field a bot will fill in.
     */
    public function testTheContactHoneypotIsHiddenFromAssistiveTechnologyAndFromTheKeyboard(): void
    {
        $html = $this->view()->render('site/contact', ['view' => ContactView::build(
            $this->pageView($this->doc(), '/contact', 'Contact'),
            csrf: 'csrf-token-value',
            formToken: 'form-token-value',
        )]);

        $found = preg_match('/<div([^>]*)>\s*<label>[^<]*<input type="text" name="website"([^>]*)>/', $html, $m);
        $this->assertSame(1, $found, 'the honeypot is a field inside its own wrapper');

        $this->assertStringContains('aria-hidden="true"', $m[1], 'the wrapper is out of the accessibility tree');
        $this->assertStringContains('position:absolute', $m[1], 'and out of sight');
        $this->assertStringContains('tabindex="-1"', $m[2], 'tabbing through the form never lands in it');
        $this->assertStringContains('autocomplete="off"', $m[2], 'a password manager does not fill it either');

        // The policy the field exists for: nothing on the page says what it is
        // for, and a filled one is discarded in silence rather than answered.
        $this->assertStringNotContains('honeypot', $html);
        $this->assertStringNotContains('aria-label="Website"', $html);
    }
}
