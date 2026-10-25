<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * The 14 px floor, held in the stylesheet rather than only in a browser.
 *
 * WHAT WENT WRONG. docs/quality-bar.md now says the floor covers the whole
 * site, `/admin` included, and the browser harness measures it - but the
 * harness only runs when somebody has Node, Chrome, a database and a signed-in
 * admin session, which is never at the moment a stylesheet is edited.
 * `tokens.css` raises the type scale inside `.site` and leaves `:root` at the
 * smaller values, so the admin inherited 0.7-0.82rem on `.tag`, `.count`,
 * `.meta` and every eyebrow: 95 leaf elements under 14 px on the rates page,
 * the screen the owner keys prices into all day. Nothing failed. Nobody saw it.
 *
 * So the same rule is asserted here, off the source, in milliseconds:
 *
 *   1. the root really is 17px, because every rem figure below depends on it;
 *   2. admin.css declares no literal font size under 0.83rem (14.1px);
 *   3. the admin re-declares the tokens it would otherwise inherit too small;
 *   4. the contract still says so in words, so the number and the sentence
 *      cannot drift apart again.
 *
 * `clamp()` and `var()` values are skipped deliberately: their smallest branch
 * is checked where it is DECLARED (rule 3), and re-parsing a clamp here would
 * be a second, worse implementation of the browser's cascade.
 */
final class AdminTypeScaleTest extends TestCase
{
    /** The root font size tokens.css sets. Every rem figure below is read against it. */
    private const ROOT_PX = 17.0;

    /** docs/quality-bar.md §1: no text anybody reads renders below this. */
    private const FLOOR_PX = 14.0;

    /** Tokens the admin inherits from :root and must re-declare at or above the floor. */
    private const INHERITED_TOKENS = ['--fs-eyebrow'];

    public function testTheRootIsStillSeventeenPixelsSoEveryRemFigureMeansWhatItSays(): void
    {
        $tokens = $this->css('tokens.css');

        $this->assertTrue(
            preg_match('/html\s*\{[^}]*font-size:\s*([0-9.]+)px/s', $tokens, $m) === 1,
            'tokens.css no longer sets an explicit px font size on html'
        );
        $this->assertSame(
            self::ROOT_PX,
            (float) $m[1],
            'the root moved to ' . $m[1] . 'px, so every rem threshold in this test and in admin.css now means '
            . 'a different number of pixels. Re-check the floor before changing this constant.'
        );
    }

    public function testAdminCssDeclaresNoTextBelowTheFloor(): void
    {
        $tooSmall = [];

        foreach ($this->declaredFontSizes('admin.css') as $line => $value) {
            if (preg_match('/^([0-9.]+)rem$/', $value, $m) === 1) {
                $px = (float) $m[1] * self::ROOT_PX;
            } elseif (preg_match('/^([0-9.]+)px$/', $value, $m) === 1) {
                $px = (float) $m[1];
            } else {
                // clamp(), var(), calc(), % - covered by the token test below.
                continue;
            }

            if ($px < self::FLOOR_PX - 0.01) {
                $tooSmall[] = 'admin.css:' . $line . '  font-size: ' . $value . '  (' . round($px, 2) . 'px)';
            }
        }

        $this->assertSame(
            [],
            $tooSmall,
            "docs/quality-bar.md §1 puts the floor at 14px for the admin too. These declarations are under it:\n    "
            . implode("\n    ", $tooSmall)
        );
    }

    /**
     * The admin must not inherit a :root token that is smaller than the floor.
     *
     * This is the exact shape of the original bug: the token was fine for the
     * public site, which overrides it inside `.site`, and far too small
     * everywhere else. A `font-size: var(--fs-eyebrow)` in admin.css reads as
     * innocent and renders at 12.24px.
     */
    public function testTheAdminRedeclaresEveryTokenItWouldOtherwiseInheritTooSmall(): void
    {
        $admin = $this->css('admin.css');

        foreach (self::INHERITED_TOKENS as $token) {
            $root = $this->tokenValue($this->css('tokens.css'), $token);
            if ($root === null || $root >= self::FLOOR_PX - 0.01) {
                // Nothing to override: :root is already at or above the floor.
                continue;
            }

            $override = $this->tokenValue($admin, $token);

            $this->assertNotNull(
                $override,
                'tokens.css sets ' . $token . ' to ' . round($root, 2) . 'px and admin.css does not override it, '
                . 'so every admin element that uses it renders under the 14px floor'
            );
            $this->assertTrue(
                $override >= self::FLOOR_PX - 0.01,
                'admin.css overrides ' . $token . ' to ' . round((float) $override, 2) . 'px, still under the floor'
            );
        }
    }

    /**
     * The number and the sentence, together.
     *
     * The floor lived only in the harness (MIN_FONT_PX) and in
     * docs/browser-tests.md, while docs/quality-bar.md - the file the audits
     * score against - never mentioned it. That is how a stylesheet and a
     * contract end up disagreeing with nobody at fault.
     */
    public function testTheContractStillStatesTheFloorAndSaysItCoversTheAdmin(): void
    {
        $bar = $this->doc('quality-bar.md');

        $this->assertStringContains('14 px floor', $bar, 'docs/quality-bar.md no longer states the floor');
        $this->assertStringContains('`/admin` included', $bar, 'the contract no longer says the floor covers the admin');

        $harness = $this->file(dirname(Paths::publicDir()) . '/tests/browser/harness.cjs', 'tests/browser/harness.cjs');
        $this->assertTrue(
            preg_match('/MIN_FONT_PX\s*=\s*([0-9.]+)/', $harness, $m) === 1,
            'the harness no longer declares MIN_FONT_PX'
        );
        $this->assertSame(
            self::FLOOR_PX,
            (float) $m[1],
            'the browser harness measures against ' . $m[1] . 'px while the contract says ' . self::FLOOR_PX
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every `font-size:` declaration in a stylesheet, by line.
     *
     * @return array<int,string> line number => the declared value
     */
    private function declaredFontSizes(string $name): array
    {
        $out = [];

        foreach (explode("\n", $this->css($name)) as $i => $raw) {
            if (preg_match('/font-size:\s*([^;]+);/', $raw, $m) === 1) {
                $out[$i + 1] = trim($m[1]);
            }
        }

        return $out;
    }

    /** The smallest px value a custom property is declared at, or null when it is absent. */
    private function tokenValue(string $css, string $token): ?float
    {
        preg_match_all('/' . preg_quote($token, '/') . ':\s*([^;]+);/', $css, $found);

        $smallest = null;
        foreach ($found[1] as $value) {
            $value = trim($value);
            if (preg_match('/^([0-9.]+)rem$/', $value, $m) === 1) {
                $px = (float) $m[1] * self::ROOT_PX;
            } elseif (preg_match('/^([0-9.]+)px$/', $value, $m) === 1) {
                $px = (float) $m[1];
            } else {
                continue;
            }
            $smallest = $smallest === null ? $px : min($smallest, $px);
        }

        return $smallest;
    }

    private function css(string $name): string
    {
        return $this->file(Paths::publicDir() . '/assets/css/' . $name, 'public/assets/css/' . $name);
    }

    private function doc(string $name): string
    {
        return $this->file(dirname(Paths::publicDir()) . '/docs/' . $name, 'docs/' . $name);
    }

    private function file(string $path, string $label): string
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            $this->fail($label . ' is missing');
        }

        return $text;
    }
}
