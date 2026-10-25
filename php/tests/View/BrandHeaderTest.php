<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Core\Paths;
use Speedat\View\HomeView;
use Speedat\View\PageView;

/**
 * The yellow header, pinned to `docs/brand.md` (owner, 2026-09-28).
 *
 * Every rule in that file is here because it was a REAL regression risk, and
 * each one fails for a different reason if it is undone:
 *
 *   - white text on the bar. The contrast is computed here, from the token
 *     values themselves, with the WCAG 2.x formula - not copied from the doc.
 *     If somebody changes `--brand-yellow` to a deeper gold, this test tells
 *     them what it did to every word on the bar.
 *   - the stripe mark or the wordmark left on a yellow bar, or the tagline
 *     printed beside artwork that already contains it.
 *   - the logo without `width`/`height` (the row jumps while it loads) or
 *     without the 2x source (soft on a phone).
 *   - the bar taking a third row on a phone. The first screen belongs to the
 *     price, and the header's job is to stay out of its way.
 *   - the brand tokens redeclared in the dark block, which would invert a bar
 *     that must not invert.
 *   - the print stylesheet inheriting a solid yellow block.
 *
 * The geometry is proved in a browser as well (`docs/browser-tests.md`); this
 * is the half that runs without one, so a tidy-up is caught by `bin/test.php`.
 */
final class BrandHeaderTest extends ViewTestCase
{
    private const YELLOW = '#ffcc00';
    private const INK    = '#0a1a33';
    private const WHITE  = '#ffffff';

    private function css(string $file): string
    {
        $css = file_get_contents(Paths::publicDir() . '/assets/css/' . $file);

        $this->assertTrue($css !== false && $css !== '', $file . ' is readable');

        return (string) $css;
    }

    private function homePage(): string
    {
        $doc = $this->doc();

        return $this->view()->render('site/home', ['view' => HomeView::build(
            $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS])
        )]);
    }

    /** WCAG 2.x relative luminance of `#rrggbb`. */
    private function luminance(string $hex): float
    {
        $hex   = ltrim($hex, '#');
        $parts = [];

        foreach ([0, 2, 4] as $at) {
            $channel = hexdec(substr($hex, $at, 2)) / 255;
            // The sRGB transfer curve, exactly as the specification writes it.
            $parts[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
    }

    /** The WCAG contrast ratio between two `#rrggbb` colours, rounded to a tenth. */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 1);
    }

    /** The value a custom property is given, last declaration wins. */
    private function token(string $css, string $name): ?string
    {
        if (preg_match_all('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $m) < 1) {
            return null;
        }

        return strtolower(trim((string) end($m[1])));
    }

    /**
     * THE NUMBERS THE DOC CLAIMS, RECOMPUTED FROM THE TOKENS.
     *
     * `docs/brand.md` prints 10.6 : 1 for the ink and 1.2 : 1 for white. Run
     * through the WCAG 2.x formula against the yellow the same file specifies,
     * the answers are **11.5 : 1** and **1.5 : 1** - and the doc's pair cannot
     * both come from one background colour (1.2 : 1 for white needs a yellow
     * around L = 0.83, 10.6 : 1 for navy one around L = 0.59). The doc's
     * DECISIONS are right and unchanged - ink passes AAA with room to spare,
     * white fails everything - so the numbers here are the arithmetic and the
     * doc's are noted as conservative. Whoever reconciles brand.md should use
     * these; the method is in this file and can be re-run.
     */
    public function testTheBarsInkPassesAaaAndWhiteOnItFailsEverything(): void
    {
        $tokens = $this->css('tokens.css');

        $this->assertSame(self::YELLOW, $this->token($tokens, '--brand-yellow'), 'the bar is #FFCC00');
        $this->assertSame(self::INK, $this->token($tokens, '--brand-ink'), 'the ink on the bar is #0A1A33');

        $ink = $this->contrast(self::INK, self::YELLOW);
        $this->assertSame(11.5, $ink, 'navy on the brand yellow');
        $this->assertTrue($ink >= 7.0, 'the bar must pass AAA for body text, not merely AA');

        $white = $this->contrast(self::WHITE, self::YELLOW);
        $this->assertSame(1.5, $white, 'white on the brand yellow');
        $this->assertTrue($white < 4.5, 'white on the bar fails AA, which is why no rule may set it');

        // And the reason the nav could not simply keep --muted: it clears AA by
        // a hair in the light theme and is cream in the dark one, on a bar that
        // does not invert. Ink is the only colour that is right in both.
        $muted = $this->contrast('#4f5866', self::YELLOW);
        $this->assertTrue($muted < 7.0, 'the muted grey does not reach AAA on the bar (it is ' . $muted . ':1)');

        // The two colours that ARE allowed near the bar, for the record:
        // white inside the green WhatsApp button, and ink on the Call button's
        // white ground. Neither relates to the yellow.
        $this->assertTrue($this->contrast(self::WHITE, '#0e7a3f') >= 4.5, 'white on WhatsApp green passes AA inside the button');
        $this->assertTrue($this->contrast(self::INK, self::WHITE) >= 7.0, 'ink on the Call button\'s white ground passes AAA');
    }

    /**
     * THE FOCUS RING IS A COLOUR ON THE BAR TOO, and the yellow broke it.
     *
     * tokens.css rings every control with `--focus`, an orange that is strong
     * on the page's own white or near-black ground. On #FFCC00 it is not:
     * measured in Chrome and recomputed here, #ea580c is 2.35:1 and the dark
     * theme's #fb923c is 1.50:1, where WCAG 2.1 SC 1.4.11 asks 3:1 of a focus
     * indicator. This is not an edge: the FIRST Tab on every page lands on the
     * brand link inside this bar, so it is the first ring a keyboard user sees.
     *
     * The fix is the same one every word on the bar already got - ink, 11.5:1,
     * constant because the bar does not invert - and this test exists so that
     * "tidy up the duplicate outline rule" cannot silently put the orange back.
     */
    public function testTheFocusRingOnTheBarIsInkBecauseTheOrangeOneVanishesOnYellow(): void
    {
        $tokens = $this->css('tokens.css');
        $site   = $this->css('site.css');

        $this->assertTrue(
            preg_match('/^:root\[data-theme="dark"\]\s*\{/m', $tokens, $m, PREG_OFFSET_CAPTURE) === 1,
            'tokens.css has a dark block',
        );
        $at = (int) $m[0][1];

        // token() takes the LAST declaration, so the light value has to be read
        // from the file above the dark block rather than from the whole of it.
        $light = $this->token(substr($tokens, 0, $at), '--focus');
        $dark  = $this->token(substr($tokens, $at), '--focus');

        $this->assertNotNull($light, '--focus is declared for the light theme');
        $this->assertNotNull($dark, '--focus is declared for the dark theme');

        // The numbers that make the override necessary. If somebody changes
        // --focus to something that DOES clear 3:1 on the yellow, this fails and
        // tells them the override may go - which is the useful failure.
        $this->assertSame(2.4, $this->contrast((string) $light, self::YELLOW), 'the light focus orange on the bar');
        $this->assertSame(1.5, $this->contrast((string) $dark, self::YELLOW), 'the dark focus orange on the bar');

        foreach ([$light, $dark] as $orange) {
            $this->assertTrue(
                $this->contrast((string) $orange, self::YELLOW) < 3.0,
                'the page focus ring fails SC 1.4.11 on the bar, which is why the bar overrides it',
            );
        }

        // And the override itself, in the header block, aimed at anything on the
        // bar rather than at today's four controls by name.
        $this->assertTrue(
            preg_match('/\.site-head :focus-visible\s*\{[^}]*outline-color:\s*var\(--brand-ink\)/s', $site) === 1,
            'site.css rings anything focused on the bar with --brand-ink',
        );
        $this->assertTrue(
            $this->contrast(self::INK, self::YELLOW) >= 3.0,
            'and that ring clears SC 1.4.11 on the yellow',
        );
    }

    /**
     * The three brand tokens are declared ONCE. Redeclaring them in the dark
     * block is the one edit that turns every word on the bar cream-on-yellow.
     */
    public function testTheBrandTokensAreConstantInBothThemes(): void
    {
        $tokens = $this->css('tokens.css');

        // The RULE, not the file header's prose about it: match it at the start
        // of a line, where a selector is written.
        $this->assertTrue(
            preg_match('/^:root\[data-theme="dark"\]\s*\{/m', $tokens, $m, PREG_OFFSET_CAPTURE) === 1,
            'tokens.css has a dark block',
        );
        $dark = substr($tokens, (int) $m[0][1]);

        foreach (['--brand-yellow', '--brand-yellow-dark', '--brand-ink'] as $name) {
            $this->assertNotNull($this->token($tokens, $name), $name . ' is declared');
            $this->assertStringNotContains($name . ':', $dark, $name . ' must not be redeclared for dark mode');
        }
    }

    /** The bar takes its colours from the brand tokens and hands them to its words. */
    public function testTheHeaderIsPaintedYellowWithInkWords(): void
    {
        $site = $this->css('site.css');

        $this->assertTrue(
            preg_match('/\.site-head\s*\{[^}]*background:\s*var\(--brand-yellow\)/s', $site) === 1,
            'site.css paints .site-head with --brand-yellow',
        );
        $this->assertTrue(
            preg_match('/\.site-head\s*\{[^}]*color:\s*var\(--brand-ink\)/s', $site) === 1,
            'site.css sets the bar\'s text colour to --brand-ink',
        );
        $this->assertTrue(
            preg_match('/\.site-head\s*\{[^}]*border-bottom:\s*1px solid var\(--brand-yellow-dark\)/s', $site) === 1,
            'the bar has its hairline edge, not a shadow',
        );
        // The nav is the one place --muted and --ink survived the navy design.
        $this->assertTrue(
            preg_match('/\.site-head \.nav a[^{]*\{\s*color:\s*var\(--brand-ink\)/s', $site) === 1,
            'the page links on the bar are ink in every state',
        );
        // Nothing on the bar may ask for white words.
        $head = $this->headerBlock($site);
        foreach (['#fff;', '#ffffff;', 'var(--wa-ink)', 'var(--paper-ink)'] as $white) {
            $this->assertTrue(
                !preg_match('/color:\s*' . preg_quote($white, '/') . '/i', $head),
                'no rule in the header block sets white text: ' . $white,
            );
        }
    }

    /**
     * site.css from `.site-head` up to the footer - the SHELL block's header
     * rules, which is where a white-text regression would land.
     */
    private function headerBlock(string $css): string
    {
        $from = strpos($css, "\n.site-head {");
        $this->assertTrue($from !== false, 'site.css still has a .site-head rule');

        // The footer's own rule, which is written after the header's - not the
        // shared full-bleed rule above, which names both landmarks at once.
        $to = strpos($css, "\nfooter.site-foot {", (int) $from);
        $this->assertTrue($to !== false, 'site.css still has a footer block after the header one');

        return substr($css, (int) $from, (int) $to - (int) $from);
    }

    /** The phone bar is one 56px row and gains nothing: <= 64px, nav row included. */
    public function testThePhoneHeaderDoesNotGrow(): void
    {
        $site = $this->css('site.css');

        $this->assertTrue(
            preg_match('/\.head-row\s*\{[^}]*height:\s*(\d+)px/s', $site, $m) === 1,
            'site.css gives .head-row a phone height',
        );
        $this->assertTrue((int) $m[1] <= 64, 'the phone header is at most 64px, nav row included (it is ' . $m[1] . 'px)');

        // And the nav is not in it below 1024, so there is no second row to add.
        $this->assertTrue(
            preg_match('/\.head-row > \.nav\s*\{\s*display:\s*none/s', $site) === 1,
            'the page links stay out of the phone header',
        );
    }

    /** 36-44px tall: below that the serif tagline inside the artwork turns to mush. */
    public function testTheLogoIsRenderedInsideTheLegibleBand(): void
    {
        $site = $this->css('site.css');

        $this->assertTrue(preg_match_all('/\.brand-logo\s*\{[^}]*height:\s*(\d+)px/s', $site, $m) >= 1, '.brand-logo has a height');

        foreach ($m[1] as $height) {
            $this->assertTrue(
                (int) $height >= 36 && (int) $height <= 44,
                'the logo renders between 36 and 44px tall (found ' . $height . 'px)',
            );
        }

        // width:auto, never a second length: the artwork is 4.96:1 and stretching it is the regression.
        $this->assertTrue(
            preg_match('/\.brand-logo\s*\{[^}]*width:\s*auto/s', $site) === 1,
            'the logo keeps its aspect ratio (width: auto)',
        );
    }

    /** The markup: one image, both sources, real dimensions, the company name as alt. */
    public function testTheHeaderRendersTheLogoFileAndNothingElse(): void
    {
        $html = $this->homePage();
        $head = $this->headerMarkup($html);

        $this->assertStringContains('class="brand-logo"', $head, 'the header renders the logo file');
        $this->assertStringContains('img/brand/speedat-logo.png', $head, 'the 1x source');
        $this->assertStringContains('img/brand/speedat-logo@2x.png', $head, 'the 2x source is in srcset');
        $this->assertStringContains('srcset=', $head, 'srcset is present');
        // The file's own pixels, so the row reserves the right box before the image arrives.
        $this->assertStringContains('width="660" height="133"', $head, 'explicit intrinsic width and height');
        $this->assertStringContains('alt="Speedat International Courier"', $head, 'alt is the company name, not "logo"');

        // What must NOT be there any more.
        $this->assertStringNotContains('class="mark"', $head, 'the stripe mark is gone from the site header');
        $this->assertStringNotContains('class="brand-name"', $head, 'the wordmark is gone from the site header');
    }

    /**
     * The tagline lives INSIDE the artwork. Printing it beside the image says
     * it twice - once as pixels and once as words.
     */
    public function testTheTaglineIsNotPrintedBesideTheArtwork(): void
    {
        $head = $this->headerMarkup($this->homePage());

        foreach (['Speed Against Time', 'speed against time', 'Speed against time'] as $tagline) {
            $this->assertStringNotContains($tagline, $head, 'the tagline is in the artwork, not beside it');
        }
    }

    /** The rendered `<header class="site-head">…</header>`. */
    private function headerMarkup(string $html): string
    {
        $from = strpos($html, '<header class="site-head">');
        $this->assertTrue($from !== false, 'the page has the site header');

        $to = strpos($html, '</header>', (int) $from);
        $this->assertTrue($to !== false, 'the site header is closed');

        return substr($html, (int) $from, (int) $to - (int) $from);
    }

    /**
     * ON PAPER THERE IS NO YELLOW. `background: none` is the shorthand and so
     * resets background-color too; `background-image: none` would leave a solid
     * block of brand colour across the top of every printed sheet.
     */
    public function testThePrintStylesheetDoesNotInheritASolidYellowBlock(): void
    {
        $print = $this->css('print.css');

        $this->assertTrue(
            preg_match('/\.site-head\s*\{[^}]*background:\s*none/s', $print) === 1,
            'print.css clears the bar\'s background with the shorthand',
        );
        $this->assertStringNotContains('--brand-yellow', $print, 'print.css never paints the brand colour');
        $this->assertStringNotContains('#ffcc00', strtolower($print), 'print.css never paints the brand colour');
        $this->assertTrue(
            preg_match('/\.brand-logo\s*\{[^}]*max-height:/s', $print) === 1,
            'print.css caps the logo so a 660px PNG does not spend half a page',
        );
    }

    /** The files brand.md names are actually there, at the sizes it names. */
    public function testTheLogoFilesExistAtTheDocumentedSizes(): void
    {
        $dir = Paths::publicDir() . '/assets/img/brand/';

        foreach (['speedat-logo.png' => [660, 133], 'speedat-logo@2x.png' => [1320, 267]] as $file => $size) {
            $this->assertTrue(is_file($dir . $file), $file . ' is shipped');

            $actual = getimagesize($dir . $file);
            $this->assertTrue($actual !== false, $file . ' is a readable image');
            $this->assertSame($size[0], $actual[0], $file . ' width');
            $this->assertSame($size[1], $actual[1], $file . ' height');
        }
    }
}
