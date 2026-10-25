<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Tests\TestCase;

/**
 * The two numbers on a phone that decide whether the calculator answers the
 * question a visitor came with, pinned here because neither can be seen by
 * reading the rule that causes it.
 *
 *   - THE PRICE IS THE LARGEST THING ON THE PAGE. The headline's size is a
 *     clamp in tokens.css and the figure's is a literal in calculator.css, in
 *     different files and different units, so "the headline is bigger than the
 *     price" was true for months without anyone reading a line that said so.
 *     At 375 the clamp bottoms out at 29.75px and the figure is 28.05px.
 *   - THE PRICE BAR IS PINNED BELOW 1024, NOT STUCK TO ITS OWN BLOCK. Sticky
 *     pins an element only while its containing block is on screen; the price
 *     bar's containing block is the last thing in the calculator, so with a
 *     long destination list it began below the fold and took the bar with it -
 *     the price and the green button landed at y=804 and y=857 on an 812px
 *     screen. `position: fixed` is not a style choice here, it is the fix.
 *
 * These are source assertions, the same kind and for the same reason as
 * ViewAdmin\AdminStylesheetTest: a browser proves the geometry (the Playwright
 * walk does), and this stops the rule that makes it true from being deleted by
 * someone tidying up who cannot see what it was for.
 */
final class SiteStylesheetTest extends TestCase
{
    /** 1rem is 17px: tokens.css sets `html { font-size: 17px }`. */
    private const ROOT_PX = 17.0;

    /** The phone breakpoint the site's own tokens use for its type scale. */
    private const PHONE_MAX = 719;

    private function css(string $file): string
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/' . $file);

        $this->assertTrue($css !== false && $css !== '', $file . ' is readable');

        return (string) $css;
    }

    /** `1.55rem` / `28px` -> pixels. */
    private function px(string $value): float
    {
        if (preg_match('/^([0-9.]+)(rem|px)$/', trim($value), $m) !== 1) {
            $this->fail('not a literal length: ' . $value);
        }

        return $m[2] === 'rem' ? ((float) $m[1]) * self::ROOT_PX : (float) $m[1];
    }

    /**
     * The `font-size` a selector declares.
     *
     * `$last` picks between the two questions this file asks: the phone
     * override is the last `.hero-copy h1` rule in site.css, while the size
     * that applies BELOW 1024 is the FIRST `.tval` rule in calculator.css -
     * the later one is inside the 1024 query and is the laptop's.
     */
    private function fontSize(string $css, string $selector, bool $last = true): ?string
    {
        $pattern = '/(?:^|[,}\s])' . preg_quote($selector, '/') . '\s*\{[^}]*?font-size:\s*([^;}]+)[;}]/s';

        if (preg_match_all($pattern, $css, $matches) < 1) {
            return null;
        }

        return trim((string) ($last ? end($matches[1]) : $matches[1][0]));
    }

    /**
     * The block of a `@media (max-width: N)` query whose condition holds at
     * `$width`, for every such query in the file, concatenated in file order.
     * Good enough for this stylesheet, which writes one condition per query.
     */
    private function phoneBlocks(string $css, int $width): string
    {
        $out = '';
        $len = strlen($css);

        if (preg_match_all('/@media\s*\(max-width:\s*(\d+)px\)\s*\{/', $css, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $out;
        }

        foreach ($matches[0] as $i => $match) {
            if ((int) $matches[1][$i][0] < $width) {
                continue;
            }

            // Walk the braces from the query's own, so a nested rule does not
            // end the block early.
            $depth = 0;
            for ($at = (int) $match[1] + strlen((string) $match[0]) - 1; $at < $len; $at++) {
                if ($css[$at] === '{') {
                    $depth++;
                } elseif ($css[$at] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $out .= substr($css, (int) $match[1], $at - (int) $match[1] + 1);
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * THE PRICE OUTRANKS THE HEADLINE ON A PHONE.
     *
     * Both sizes are read from the stylesheets and compared in pixels: the
     * headline from the phone override in site.css (falling back to the
     * `--fs-h1` clamp's minimum, which is what applies without it), the figure
     * from `.tval`'s own rule, which is the one in force below 1024.
     */
    public function testThePriceIsLargerThanTheHomeHeadlineAtPhoneWidth(): void
    {
        $site = $this->css('site.css');
        $calc = $this->css('calculator.css');

        $override = $this->fontSize($this->phoneBlocks($site, self::PHONE_MAX), '.hero-copy h1');
        $this->assertNotNull($override, 'site.css sizes the home headline for phones');

        $figure = $this->fontSize($calc, '.tval', last: false);
        $this->assertNotNull($figure, 'calculator.css sizes the price bar figure');
        $this->assertTrue(
            !str_contains((string) $figure, 'var('),
            'the figure below 1024 is a literal size, not the desktop token'
        );

        $headlinePx = $this->px((string) $override);
        $figurePx   = $this->px((string) $figure);

        $this->assertTrue(
            $headlinePx < $figurePx,
            sprintf('the phone headline is %.2fpx and the price is %.2fpx - the price must be the larger', $headlinePx, $figurePx)
        );
    }

    /**
     * And the override is not decoration: without it the inherited clamp is
     * BIGGER than the figure, which is the bug it exists to hold shut. If
     * someone lowers the token instead, this says so rather than passing.
     */
    public function testTheHeadlineOverrideIsWhatKeepsThePriceOnTop(): void
    {
        $tokens = $this->css('tokens.css');
        $calc   = $this->css('calculator.css');

        // `--fs-h1: clamp(1.75rem, ...)` inside the `.site` scale.
        $found = preg_match('/\.site\s*\{[^}]*--fs-h1:\s*clamp\(\s*([0-9.]+rem)\s*,/s', $tokens, $m);
        $this->assertSame(1, $found, 'the customer scale still sets --fs-h1 as a clamp');

        $clampFloorPx = $this->px($m[1]);
        $figurePx     = $this->px((string) $this->fontSize($calc, '.tval', last: false));

        $this->assertTrue(
            $clampFloorPx >= $figurePx,
            sprintf(
                'the inherited headline floor (%.2fpx) is already under the price (%.2fpx); the phone override in site.css is now redundant and its comment is wrong',
                $clampFloorPx,
                $figurePx
            )
        );
    }

    /**
     * THE PRICE BAR IS PINNED BELOW 1024 AND IN THE FLOW FROM 1024.
     *
     * `position: sticky` here is the regression: it puts the bar on screen
     * only once its own block scrolls into range, which with ten destinations
     * is after the fold.
     */
    public function testThePriceBarIsPinnedBelowTenTwentyFourAndStaticAbove(): void
    {
        $calc = $this->css('calculator.css');

        // The first `.pricebar {` in the file is the base rule - the one in
        // force below 1024. (The later ones are the 1024 query's and the
        // entrance animation's.)
        // Anchored at the start of a line, which only a top-level rule is:
        // everything inside a media query is indented. So this is the base
        // rule - the one in force below 1024 - and not the 1024 query's or
        // the entrance animation's.
        $found = preg_match('/^\.pricebar\s*\{(.*?)\}/ms', $calc, $m);
        $this->assertSame(1, $found, 'calculator.css has a top-level .pricebar rule');

        $this->assertStringContains('position: fixed;', $m[1], 'the price bar is pinned below 1024');
        $this->assertStringContains('bottom: 0;', $m[1], 'pinned to the bottom edge');
        $this->assertStringNotContains('position: sticky;', $m[1], 'sticky is the bug: it needs its own block on screen');

        // From 1024 it goes back into the flow under the rows.
        $found = preg_match('/@media\s*\(min-width:\s*1024px\)\s*\{.*?\.pricebar\s*\{(.*?)\}/s', $calc, $m);
        $this->assertSame(1, $found, 'the 1024 block still restyles .pricebar');
        $this->assertStringContains('position: static;', $m[1], 'from 1024 the bar sits under the rows, unpinned');
    }

    /**
     * A pinned bar covers the bottom of the screen, and the one thing a
     * visitor can never scroll out from under it is the end of the document.
     * So the footer reserves the bar's height - and only when a bar is
     * actually in the page.
     */
    public function testTheFooterReservesRoomForThePinnedBar(): void
    {
        $site = $this->css('site.css');

        $found = preg_match('/:has\(\.pricebar\)\s+footer\.site-foot\s*\{(.*?)\}/s', $site, $m);
        $this->assertSame(1, $found, 'site.css gives the footer room under the pinned bar');
        $this->assertStringContains('padding-bottom:', $m[1]);
        $this->assertStringContains('--tap-big', $m[1], 'the reservation is the bar height, not a guessed number');

        // Scoped to the widths where the bar is pinned; from 1024 it is in flow.
        $this->assertStringContains(
            ':has(.pricebar)',
            $this->phoneBlocks($site, 1023),
            'the footer reservation is inside a max-width query, so the laptop keeps its own footer'
        );
    }
}
