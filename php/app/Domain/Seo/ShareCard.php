<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Core\Paths;

/**
 * The Open Graph share card: what WhatsApp, Facebook and X draw instead of a
 * bare link. WhatsApp is how this business is booked, so this image is closer
 * to a shop front than to an SEO detail (docs/quality-bar.md §1).
 *
 * WHAT IT IS
 * A STATIC 1200x630 PNG shipped in public/assets/img, plus the SVG it was
 * drawn from, plus a square PNG of the brand mark for the JSON-LD `logo`. The
 * card is not generated per request: a scraper fetches it once and caches it
 * for weeks, so generating it live would buy nothing and cost a GD call on
 * every crawl. This class knows what is on it, where it is and how big it is;
 * `ShareCardRenderer` is what actually draws it, and `docs/seo.md` has the one
 * command that regenerates both files after the company name, the tagline or
 * the destination list changes.
 *
 * WHAT IS ON IT
 * Only things the document already says: the brand mark exactly as
 * public/assets/img/mark.svg freezes it, the company name, the tagline, and
 * the headline sentence the home page prints as its h1 ("Lahore to Australia,
 * Canada, France and Germany."). No review stars, no counts, no awards, no
 * "fastest" - content honesty is a standing rule, and a share card is the one
 * place a claim would travel furthest.
 *
 * THE COLOURS are the frozen light-theme brand tokens: navy ink #0a1a33, the
 * one orange #ea580c (its on-navy variant #f97316 for text, 6.2:1), paper
 * #f5f0e7. They are literals here because a PNG cannot read a CSS custom
 * property; `docs/seo.md` records that they must be re-frozen if tokens.css
 * moves.
 */
final class ShareCard
{
    public const WIDTH  = 1200;
    public const HEIGHT = 630;

    /** Square logo for structured data. Google wants a raster, not the SVG. */
    public const LOGO_SIZE = 512;

    /** Published paths, relative to public/. Renaming one is how the scrapers' caches are busted. */
    public const CARD_PNG = 'assets/img/og-default.png';
    public const CARD_SVG = 'assets/img/og-default.svg';
    public const LOGO_PNG = 'assets/img/og-logo.png';

    /** Frozen light-theme tokens (public/assets/css/tokens.css). */
    public const NAVY       = '#0a1a33';
    public const ORANGE     = '#ea580c';
    public const ORANGE_ON_NAVY = '#f97316';
    public const PAPER      = '#f5f0e7';
    public const PAPER_70   = '#b9b5ad';
    public const WHITE      = '#ffffff';
    public const HAIRLINE   = '#e6e1d8';

    public function __construct(
        private readonly SiteFacts $facts,
        private readonly Url $url,
    ) {
    }

    public function width(): int
    {
        return self::WIDTH;
    }

    public function height(): int
    {
        return self::HEIGHT;
    }

    public function logoSize(): int
    {
        return self::LOGO_SIZE;
    }

    public function mimeType(): string
    {
        return 'image/png';
    }

    /** Absolute URL of the card. Absolute is not optional: a scraper has no base to resolve against. */
    public function absoluteUrl(): string
    {
        return $this->url->absolute('/' . self::CARD_PNG);
    }

    public function logoAbsoluteUrl(): string
    {
        return $this->url->absolute('/' . self::LOGO_PNG);
    }

    /** Whether the file is actually on disk - asserted by the tests, so a missing card is a failure, not a silent 404. */
    public function exists(): bool
    {
        return is_file(Paths::publicDir() . '/' . self::CARD_PNG);
    }

    /**
     * `og:image:alt`: what the card says, for somebody who cannot see it.
     *
     * Same two sentences that are drawn on it, so the alt text and the image
     * cannot drift apart.
     */
    public function alt(): string
    {
        $plan  = $this->plan();
        $parts = array_values(array_filter([$plan['brand'], $plan['headline']], static fn (string $s): bool => $s !== ''));

        return $parts === [] ? 'Share card' : implode(' - ', $parts);
    }

    /**
     * Everything that is drawn, as strings. One source for the PNG, the SVG
     * and the alt text.
     *
     * @return array{brand:string,eyebrow:string,headline:string,footer:string}
     */
    public function plan(): array
    {
        return [
            'brand'    => $this->facts->companyName(),
            'eyebrow'  => trim($this->facts->company()->tagline),
            'headline' => $this->facts->headline(),
            'footer'   => $this->url->host(),
        ];
    }

    // ---- geometry, shared by both renderers so they cannot disagree ------

    public const PAD          = 72;
    public const MARK_X       = 72;
    public const MARK_Y       = 64;
    public const MARK_SIZE    = 92;
    public const BRAND_X      = 192;
    public const BRAND_BASE   = 126;   // baseline, vertically centred on the mark
    public const BRAND_SIZE   = 40;
    public const EYEBROW_BASE = 246;
    public const EYEBROW_SIZE = 25;
    public const HEAD_TOP     = 330;   // baseline of the first headline line
    public const HEAD_SIZE    = 60;
    public const HEAD_MIN     = 40;
    public const HEAD_LEADING = 1.18;
    public const HEAD_LINES   = 3;
    public const RULE_Y       = 524;
    public const RULE_W       = 148;
    public const RULE_H       = 7;
    public const FOOT_BASE    = 582;
    public const FOOT_SIZE    = 26;
    public const EDGE_H       = 12;    // orange band along the bottom edge

    /** Usable text width inside the padding. */
    public const TEXT_W = self::WIDTH - (self::PAD * 2);

    /**
     * The card as SVG - the source the PNG is rasterised from, and a usable
     * asset in its own right for anything that renders vectors.
     *
     * The text is `<text>` with a font stack rather than outlines, because
     * outlining would mean shipping a font's glyph data in the repository. The
     * PNG is therefore the authority on exact letterforms; the SVG is the
     * authority on layout, and the two agree on every coordinate because both
     * read the constants above.
     */
    public function svg(): string
    {
        $plan  = $this->plan();
        $lines = self::wrapByWidth($plan['headline'], self::HEAD_SIZE, self::TEXT_W, self::HEAD_LINES);
        $size  = self::HEAD_SIZE;

        // Same shrink rule the PNG renderer uses: too many lines, smaller type.
        while ($lines === null && $size > self::HEAD_MIN) {
            $size -= 4;
            $lines = self::wrapByWidth($plan['headline'], $size, self::TEXT_W, self::HEAD_LINES);
        }
        if ($lines === null) {
            $lines = self::wrapByWidth($plan['headline'], $size, self::TEXT_W, self::HEAD_LINES, true) ?? [];
        }

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::WIDTH . '" height="' . self::HEIGHT . '"'
              . ' viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img" aria-label="'
              . self::x($this->alt()) . '">' . "\n";
        $out .= '  <title>' . self::x($this->alt()) . '</title>' . "\n";
        $out .= '  <defs>' . "\n" . $this->stripePattern() . '  </defs>' . "\n";
        $out .= '  <rect width="' . self::WIDTH . '" height="' . self::HEIGHT . '" fill="' . self::NAVY . '"/>' . "\n";
        $out .= '  <rect x="0" y="' . (self::HEIGHT - self::EDGE_H) . '" width="' . self::WIDTH . '" height="'
              . self::EDGE_H . '" fill="' . self::ORANGE . '"/>' . "\n";
        $out .= '  <rect x="' . self::MARK_X . '" y="' . self::MARK_Y . '" width="' . self::MARK_SIZE . '" height="'
              . self::MARK_SIZE . '" rx="18" fill="url(#ogstripes)" stroke="' . self::HAIRLINE . '" stroke-width="2"/>' . "\n";

        $family      = "'Big Shoulders Display','Barlow',system-ui,'Segoe UI',sans-serif";
        $familyPlain = "'Barlow',system-ui,'Segoe UI',sans-serif";

        if ($plan['brand'] !== '') {
            $out .= '  <text x="' . self::BRAND_X . '" y="' . self::BRAND_BASE . '" font-family="' . self::x($family)
                  . '" font-size="' . self::BRAND_SIZE . '" font-weight="600" fill="' . self::PAPER . '">'
                  . self::x($plan['brand']) . '</text>' . "\n";
        }

        if ($plan['eyebrow'] !== '') {
            $out .= '  <text x="' . self::PAD . '" y="' . self::EYEBROW_BASE . '" font-family="' . self::x($familyPlain)
                  . '" font-size="' . self::EYEBROW_SIZE . '" font-weight="600" letter-spacing="2.4" fill="'
                  . self::ORANGE_ON_NAVY . '">' . self::x(mb_strtoupper($plan['eyebrow'])) . '</text>' . "\n";
        }

        $y = self::HEAD_TOP;
        foreach ($lines as $line) {
            $out .= '  <text x="' . self::PAD . '" y="' . $y . '" font-family="' . self::x($family)
                  . '" font-size="' . $size . '" font-weight="600" fill="' . self::PAPER . '">'
                  . self::x($line) . '</text>' . "\n";
            $y += (int) round($size * self::HEAD_LEADING);
        }

        $out .= '  <rect x="' . self::PAD . '" y="' . self::RULE_Y . '" width="' . self::RULE_W . '" height="'
              . self::RULE_H . '" fill="' . self::ORANGE_ON_NAVY . '"/>' . "\n";

        if ($plan['footer'] !== '') {
            $out .= '  <text x="' . self::PAD . '" y="' . self::FOOT_BASE . '" font-family="' . self::x($familyPlain)
                  . '" font-size="' . self::FOOT_SIZE . '" fill="' . self::PAPER_70 . '">'
                  . self::x($plan['footer']) . '</text>' . "\n";
        }

        return $out . '</svg>' . "\n";
    }

    /**
     * The brand mark as a standalone square, for the JSON-LD logo.
     *
     * Same stripe geometry as public/assets/img/mark.svg, inset on white so it
     * reads as a logo rather than as a cropped texture.
     */
    public function logoSvg(): string
    {
        $s     = self::LOGO_SIZE;
        $inset = (int) round($s * 0.10);
        $box   = $s - ($inset * 2);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 ' . $s . ' ' . $s
            . '" role="img" aria-label="' . self::x($this->facts->companyName()) . '">' . "\n"
            . '  <defs>' . "\n" . $this->stripePattern((float) $box) . '  </defs>' . "\n"
            . '  <rect width="' . $s . '" height="' . $s . '" fill="' . self::WHITE . '"/>' . "\n"
            . '  <rect x="' . $inset . '" y="' . $inset . '" width="' . $box . '" height="' . $box
            . '" rx="' . (int) round($box * 0.2) . '" fill="url(#ogstripes)" stroke="' . self::HAIRLINE . '" stroke-width="2"/>' . "\n"
            . '</svg>' . "\n";
    }

    /**
     * The mark's stripe pattern, scaled from mark.svg's worked-out geometry.
     *
     * mark.svg derives it for a 28px box: at 135 degrees the gradient line is
     * 28*sqrt(2) long, one 52% period is 20.591 of it, and the four bands fall
     * at 7.128 / 10.296 / 17.423 / 20.591. Everything here is that, times
     * size/28.
     */
    private function stripePattern(float $size = self::MARK_SIZE): string
    {
        $k = $size / 28.0;
        $f = static fn (float $v): string => rtrim(rtrim(number_format($v * $k, 3, '.', ''), '0'), '.');

        return '    <pattern id="ogstripes" patternUnits="userSpaceOnUse" width="' . $f(20.591)
            . '" height="' . $f(8) . '" patternTransform="rotate(45)">' . "\n"
            . '      <rect x="0" y="0" width="' . $f(7.128) . '" height="' . $f(8) . '" fill="' . self::ORANGE . '"/>' . "\n"
            . '      <rect x="' . $f(7.128) . '" y="0" width="' . $f(3.168) . '" height="' . $f(8) . '" fill="' . self::WHITE . '"/>' . "\n"
            . '      <rect x="' . $f(10.296) . '" y="0" width="' . $f(7.127) . '" height="' . $f(8) . '" fill="' . self::NAVY . '"/>' . "\n"
            . '      <rect x="' . $f(17.423) . '" y="0" width="' . $f(3.168) . '" height="' . $f(8) . '" fill="' . self::WHITE . '"/>' . "\n"
            . '    </pattern>' . "\n";
    }

    /**
     * Greedy word wrap using an average-advance estimate.
     *
     * Only the SVG uses it; the PNG measures real glyphs with
     * `imagettfbbox()`. 0.54em is the measured mean advance of the display
     * face's lowercase over this copy - close enough for a layout check, and
     * the reason the PNG, not the SVG, is the shipped artefact.
     *
     * @param  bool              $force true = accept an overflowing last line rather than failing
     * @return list<string>|null null when it does not fit in $maxLines
     */
    public static function wrapByWidth(string $text, int $fontSize, int $maxWidth, int $maxLines, bool $force = false): ?array
    {
        $perChar = $fontSize * 0.54;
        $budget  = $perChar > 0.0 ? (int) floor($maxWidth / $perChar) : 0;

        return self::wrapByChars($text, max(1, $budget), $maxLines, $force);
    }

    /**
     * Greedy word wrap by character budget.
     *
     * @return list<string>|null null when the text needs more than $maxLines
     */
    public static function wrapByChars(string $text, int $budget, int $maxLines, bool $force = false): ?array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }

        $lines   = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strlen($candidate) <= $budget || $current === '') {
                $current = $candidate;
                continue;
            }

            $lines[] = $current;
            $current = $word;

            if (count($lines) >= $maxLines) {
                return $force ? $lines : null;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return count($lines) <= $maxLines ? $lines : ($force ? array_slice($lines, 0, $maxLines) : null);
    }

    /** XML text/attribute escaping for the SVG this class writes. */
    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
