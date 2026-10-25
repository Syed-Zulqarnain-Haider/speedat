<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Tests\TestCase;

/**
 * The two rules about admin.css that a reader cannot check by eye, and that
 * both regressed silently once already.
 *
 * Neither is a matter of taste. They are the quality bar's numbers - 48px on
 * anything a finger reaches, nothing under 14px - and the reason they are
 * asserted HERE rather than reviewed is that both failed while looking
 * perfectly correct in the source:
 *
 *   - `.chk-bare` had `min-height: 48px` written for it in the phone block and
 *     measured 19px in a real browser, because a <label> with no text is
 *     `display: inline` and min-height on an inline box does nothing. The rule
 *     was present, readable, and inert.
 *   - The admin inherited tokens.css's :root type scale, where `--fs-eyebrow`
 *     and `.tag` / `.count` / `.meta` sit between 0.7rem and 0.82rem. The
 *     public site raises its own floor inside `.site`; the admin never did, so
 *     95 elements on the rates page - the screen the owner keys prices into
 *     all day - printed under 14px.
 *
 * A grep cannot catch either one, so the numbers are pinned instead.
 */
final class AdminStylesheetTest extends TestCase
{
    /** 1rem is 17px: tokens.css sets `html { font-size: 17px }`. */
    private const ROOT_PX = 17.0;

    /** The floor, in pixels, from php/docs/quality-bar.md. */
    private const FLOOR_PX = 14.0;

    private function css(): string
    {
        $path = dirname(__DIR__, 2) . '/public/assets/css/admin.css';
        $css  = file_get_contents($path);

        return $css === false ? '' : $css;
    }

    /**
     * NOTHING IN THE ADMIN'S OWN STYLESHEET IS UNDER 14px.
     *
     * Only literal `rem` and `px` sizes are checked. `var(...)` and `clamp(...)`
     * resolve elsewhere - tokens.css owns those, and the admin overrides the
     * ones it inherits by redefining `--fs-eyebrow` on `.admin-app`, which is
     * asserted separately below.
     */
    public function testNoFontSizeInTheAdminStylesheetIsUnderFourteenPixels(): void
    {
        $css = $this->css();
        $this->assertTrue($css !== '', 'admin.css is readable');

        $found = preg_match_all('/font-size:\s*([0-9.]+)(rem|px)\s*;/i', $css, $matches, PREG_SET_ORDER);
        $this->assertTrue($found > 0, 'the stylesheet declares some literal font sizes');

        $tooSmall = [];
        foreach ($matches as $m) {
            $px = $m[2] === 'rem' ? ((float) $m[1]) * self::ROOT_PX : (float) $m[1];
            if ($px < self::FLOOR_PX) {
                $tooSmall[] = $m[0] . ' (' . round($px, 2) . 'px)';
            }
        }

        $this->assertSame([], $tooSmall, 'admin.css prints text under 14px: ' . implode(', ', $tooSmall));
    }

    /**
     * The admin re-floors the type scale it inherits, and does it in ITS OWN
     * file rather than by moving a :root token the public site also reads.
     */
    public function testTheAdminRaisesTheInheritedEyebrowAndBadgeSizes(): void
    {
        $css = $this->css();

        $this->assertStringContains('--fs-eyebrow: 0.83rem;', $css, 'the admin redefines the eyebrow token for itself');
        foreach (['.admin-app .tag', '.admin-app .count', '.admin-app .meta'] as $selector) {
            $this->assertStringContains($selector, $css, $selector . ' is re-floored for the admin');
        }
    }

    /**
     * `.chk-bare` MUST BE A BOX. This is the whole bug: the 48px rule for it
     * already existed and did nothing, because the element was inline.
     */
    public function testTheBareCheckboxWrapperIsABoxSoItsPhoneSizeApplies(): void
    {
        $css = $this->css();

        $found = preg_match('/\.chk-bare\s*\{([^}]*)\}/', $css, $m);
        $this->assertSame(1, $found, '.chk-bare has a base rule of its own');

        $display = preg_match('/display:\s*(inline-flex|flex|inline-grid|grid|block|inline-block)\s*;/', $m[1]) === 1;
        $this->assertTrue($display, '.chk-bare needs a block-ish display or min-height on it is inert');
    }

    /** ...and the phone block still grows it to a thumb's worth of target. */
    public function testTheBareCheckboxReachesFortyEightPixelsOnAPhone(): void
    {
        $css = $this->css();

        $found = preg_match('/@media \(max-width: 719px\) \{(.*)$/s', $css, $m);
        $this->assertSame(1, $found, 'the phone block exists');

        $this->assertStringContains('label.chk-bare', $m[1], 'the phone block names the wrapper');
        $this->assertStringContains('min-width: 48px;', $m[1], 'and gives it a width a thumb can land on');
    }
}
