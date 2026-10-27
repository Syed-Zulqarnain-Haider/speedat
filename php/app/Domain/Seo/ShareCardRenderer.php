<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * Draws the share card and the logo as PNGs, with GD.
 *
 * THIS IS A BUILD-TIME TOOL, NOT A REQUEST-TIME ONE. The card is a static
 * asset; nothing on the Hostinger host ever calls this class. It runs on a
 * machine that has GD with FreeType and a TrueType font, writes the three
 * files into public/assets/img, and they are committed. `docs/seo.md` has the
 * command and the list of moments that make the card stale (the company name,
 * the tagline, the destination list, the domain).
 *
 * IT REFUSES RATHER THAN DEGRADES. No GD, no FreeType, no font: it throws.
 * GD's built-in bitmap fonts top out around 15px, and a 1200x630 card with
 * 15px text scaled up 4x is worse than no card at all - a blurry share image
 * is a worse first impression than a plain link.
 *
 * The geometry, the colours and the strings all come from `ShareCard`, so this
 * class decides nothing about what the card says; it only puts pixels where
 * `ShareCard`'s constants say.
 */
final class ShareCardRenderer
{
    /**
     * Where a usable TrueType font might be, best first.
     *
     * Geist ships inside Next's own dependencies in this repository and is SIL
     * OFL 1.1, so rendering with it and shipping the resulting image is
     * unambiguously allowed - which is why it is preferred over anything the
     * operating system happens to have. The rest are fallbacks so the command
     * works on a Linux box too.
     */
    private const FONT_GLOBS = [
        '{root}/public/assets/fonts/*.ttf',
        '{repo}/node_modules/.pnpm/*/node_modules/next/dist/compiled/@vercel/og/Geist-Regular.ttf',
        '{repo}/node_modules/next/dist/compiled/@vercel/og/Geist-Regular.ttf',
        'C:/Windows/Fonts/bahnschrift.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/Library/Fonts/Arial.ttf',
    ];

    public function __construct(
        private readonly ShareCard $card,
        /** Absolute path to a .ttf or .otf file. */
        private readonly string $fontPath,
    ) {
        if (!extension_loaded('gd')) {
            throw new SeoError('The share card needs the GD extension. Run the command on a machine that has it; the card itself is a committed file, so the server never needs GD.');
        }

        $info = gd_info();
        if (($info['FreeType Support'] ?? false) !== true) {
            throw new SeoError('GD is present but was built without FreeType, so it cannot draw text from a font file.');
        }

        if (!is_file($this->fontPath)) {
            throw new SeoError('Font file not found: ' . $this->fontPath);
        }
    }

    /**
     * The first font on the candidate list that exists.
     *
     * @param string $phpRoot the php/ directory; the repository root is its parent
     */
    public static function findFont(string $phpRoot): ?string
    {
        $phpRoot = rtrim(str_replace('\\', '/', $phpRoot), '/');
        $repo    = dirname($phpRoot);

        foreach (self::FONT_GLOBS as $pattern) {
            $expanded = str_replace(['{root}', '{repo}'], [$phpRoot, $repo], $pattern);

            foreach (glob($expanded) ?: [] as $match) {
                if (is_file($match)) {
                    return $match;
                }
            }
        }

        return null;
    }

    /** The 1200x630 share card, as PNG bytes. */
    public function png(): string
    {
        $plan   = $this->card->plan();
        $canvas = $this->canvas(ShareCard::WIDTH, ShareCard::HEIGHT, ShareCard::NAVY);

        $paper    = $this->colour($canvas, ShareCard::PAPER);
        $paper70  = $this->colour($canvas, ShareCard::PAPER_70);
        $orange   = $this->colour($canvas, ShareCard::ORANGE);
        $orangeUp = $this->colour($canvas, ShareCard::ORANGE_ON_NAVY);

        // The orange band along the bottom edge.
        imagefilledrectangle(
            $canvas,
            0,
            ShareCard::HEIGHT - ShareCard::EDGE_H,
            ShareCard::WIDTH - 1,
            ShareCard::HEIGHT - 1,
            $orange
        );

        $this->stamp($canvas, ShareCard::MARK_X, ShareCard::MARK_Y, ShareCard::MARK_SIZE, ShareCard::NAVY);

        if ($plan['brand'] !== '') {
            $this->text($canvas, $plan['brand'], ShareCard::BRAND_X, ShareCard::BRAND_BASE, ShareCard::BRAND_SIZE, $paper, 1);
        }

        if ($plan['eyebrow'] !== '') {
            $this->tracked($canvas, mb_strtoupper($plan['eyebrow']), ShareCard::PAD, ShareCard::EYEBROW_BASE, ShareCard::EYEBROW_SIZE, $orangeUp, 2.4);
        }

        [$lines, $size] = $this->fitHeadline($plan['headline']);
        $y              = ShareCard::HEAD_TOP;
        foreach ($lines as $line) {
            $this->text($canvas, $line, ShareCard::PAD, $y, $size, $paper, 1);
            $y += (int) round($size * ShareCard::HEAD_LEADING);
        }

        imagefilledrectangle(
            $canvas,
            ShareCard::PAD,
            ShareCard::RULE_Y,
            ShareCard::PAD + ShareCard::RULE_W - 1,
            ShareCard::RULE_Y + ShareCard::RULE_H - 1,
            $orangeUp
        );

        if ($plan['footer'] !== '') {
            $this->text($canvas, $plan['footer'], ShareCard::PAD, ShareCard::FOOT_BASE, ShareCard::FOOT_SIZE, $paper70, 0);
        }

        return $this->encode($canvas);
    }

    /** The square brand mark on white, as PNG bytes, for the JSON-LD logo. */
    public function logoPng(): string
    {
        $size   = ShareCard::LOGO_SIZE;
        $canvas = $this->canvas($size, $size, ShareCard::WHITE);
        $inset  = (int) round($size * 0.10);

        $this->stamp($canvas, $inset, $inset, $size - ($inset * 2), ShareCard::WHITE);

        return $this->encode($canvas);
    }

    /**
     * Write the SVG, the card PNG and the logo PNG into public/assets/img.
     *
     * @param  string             $publicDir absolute path to php/public
     * @return array<string,int>  published path => bytes written
     */
    public function write(string $publicDir): array
    {
        $dir = rtrim(str_replace('\\', '/', $publicDir), '/');

        $files = [
            ShareCard::CARD_SVG => $this->card->svg(),
            ShareCard::CARD_PNG => $this->png(),
            ShareCard::LOGO_PNG => $this->logoPng(),
        ];

        $written = [];
        foreach ($files as $path => $bytes) {
            $target = $dir . '/' . $path;
            if (!is_dir(dirname($target))) {
                throw new SeoError('Missing directory for the share card: ' . dirname($path));
            }
            if (file_put_contents($target, $bytes) === false) {
                throw new SeoError('Could not write ' . $path);
            }
            $written[$path] = strlen($bytes);
        }

        return $written;
    }

    // ---- drawing ---------------------------------------------------------

    /**
     * The headline at the largest size that still fits in three lines, and
     * the lines it broke into. Measured with real glyph metrics, not guessed.
     *
     * @return array{0:list<string>,1:int}
     */
    private function fitHeadline(string $text): array
    {
        for ($size = ShareCard::HEAD_SIZE; $size >= ShareCard::HEAD_MIN; $size -= 4) {
            $lines = $this->wrap($text, $size, ShareCard::TEXT_W);
            if (count($lines) <= ShareCard::HEAD_LINES) {
                return [$lines, $size];
            }
        }

        // Still too long at the smallest size: keep the first three lines
        // rather than running the text off the bottom of the card.
        $lines = $this->wrap($text, ShareCard::HEAD_MIN, ShareCard::TEXT_W);

        return [array_slice($lines, 0, ShareCard::HEAD_LINES), ShareCard::HEAD_MIN];
    }

    /**
     * Greedy word wrap against measured widths.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }

        $lines   = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($current === '' || $this->widthOf($candidate, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function widthOf(string $text, int $size): int
    {
        $box = imagettfbbox((float) $size, 0.0, $this->fontPath, $text);

        if ($box === false) {
            throw new SeoError('GD could not measure text with ' . basename($this->fontPath));
        }

        return (int) ceil(max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * Draw text, with a cheap weight emulation.
     *
     * The brand faces (Barlow, Big Shoulders) are woff2-only in this
     * repository and GD cannot read woff2, so the card is drawn in whatever
     * TrueType face `findFont()` produced. `$weight` redraws the string with
     * one-pixel offsets, which thickens a regular face enough to read as the
     * semibold the design calls for.
     */
    private function text(\GdImage $canvas, string $text, int $x, int $baseline, int $size, int $colour, int $weight): void
    {
        $offsets = [[0, 0]];

        if ($weight > 0) {
            $offsets[] = [1, 0];
            $offsets[] = [0, -1];
            $offsets[] = [1, -1];
        }

        foreach ($offsets as [$dx, $dy]) {
            imagettftext($canvas, (float) $size, 0.0, $x + $dx, $baseline + $dy, $colour, $this->fontPath, $text);
        }
    }

    /** Draw a string one character at a time with extra tracking, for the eyebrow. */
    private function tracked(\GdImage $canvas, string $text, int $x, int $baseline, int $size, int $colour, float $tracking): void
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pen   = (float) $x;

        foreach ($chars as $char) {
            $this->text($canvas, $char, (int) round($pen), $baseline, $size, $colour, 1);

            $box = imagettfbbox((float) $size, 0.0, $this->fontPath, $char);
            $pen += ($box === false ? $size * 0.5 : $box[2] - $box[0]) + $tracking;
        }
    }

    /**
     * The brand mark: mark.svg's stripe pattern clipped to a rounded square,
     * drawn at four times the size and resampled down so the corners and the
     * diagonal stripe edges are smooth.
     *
     * `$behind` is the colour the tile sits on; the supersampled tile is filled
     * with it so the resampled edge blends into the card instead of onto black.
     */
    private function stamp(\GdImage $canvas, int $x, int $y, int $size, string $behind): void
    {
        $scale = 4;
        $big   = $size * $scale;

        $tile = imagecreatetruecolor($big, $big);
        imagefilledrectangle($tile, 0, 0, $big - 1, $big - 1, $this->colour($tile, $behind));

        $orange = $this->colour($tile, ShareCard::ORANGE);
        $white  = $this->colour($tile, ShareCard::WHITE);
        $navy   = $this->colour($tile, ShareCard::NAVY);
        $line   = $this->colour($tile, ShareCard::HAIRLINE);

        // mark.svg's geometry, scaled: the 135-degree gradient line is
        // big*sqrt(2) long and one period is 52% of it.
        $diagonal = $big * M_SQRT2;
        $period   = $diagonal * 0.52;
        $radius   = $big * (18.0 / 92.0); // the SVG's rx, in the same proportion

        for ($py = 0; $py < $big; $py++) {
            for ($px = 0; $px < $big; $px++) {
                if (!self::insideRounded($px, $py, $big, $radius)) {
                    continue;
                }

                $along    = (($px + $py) / M_SQRT2);
                $fraction = fmod(fmod($along, $period) + $period, $period) / $diagonal;

                $colour = match (true) {
                    $fraction < 0.18 => $orange,
                    $fraction < 0.26 => $white,
                    $fraction < 0.44 => $navy,
                    default          => $white,
                };

                imagesetpixel($tile, $px, $py, $colour);
            }
        }

        // The hairline border, drawn as the outermost ring of the rounded box.
        for ($py = 0; $py < $big; $py++) {
            for ($px = 0; $px < $big; $px++) {
                if (self::insideRounded($px, $py, $big, $radius) && !self::insideRounded($px, $py, $big, $radius, $scale * 1.0)) {
                    imagesetpixel($tile, $px, $py, $line);
                }
            }
        }

        imagecopyresampled($canvas, $tile, $x, $y, 0, 0, $size, $size, $big, $big);
        // No imagedestroy(): a GdImage is freed when it goes out of scope, and
        // PHP 8.5 deprecates the call.
    }

    /** Point-in-rounded-square, optionally shrunk by $inset on every side. */
    private static function insideRounded(int $px, int $py, int $size, float $radius, float $inset = 0.0): bool
    {
        $x = $px + 0.5 - $inset;
        $y = $py + 0.5 - $inset;
        $s = $size - (2 * $inset);
        $r = max(0.0, $radius - $inset);

        if ($x < 0 || $y < 0 || $x > $s || $y > $s) {
            return false;
        }

        $cx = min(max($x, $r), $s - $r);
        $cy = min(max($y, $r), $s - $r);

        return (($x - $cx) ** 2) + (($y - $cy) ** 2) <= $r ** 2;
    }

    private function canvas(int $width, int $height, string $background): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $this->colour($canvas, $background));

        return $canvas;
    }

    private function colour(\GdImage $image, string $hex): int
    {
        $clean = ltrim($hex, '#');
        $rgb   = (int) hexdec($clean);
        $index = imagecolorallocate($image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

        if ($index === false) {
            throw new SeoError('GD ran out of palette entries allocating ' . $hex);
        }

        return $index;
    }

    private function encode(\GdImage $canvas): string
    {
        ob_start();
        imagepng($canvas, null, 9);
        $bytes = ob_get_clean();
        unset($canvas);

        if ($bytes === false || $bytes === '') {
            throw new SeoError('GD produced no PNG data.');
        }

        return $bytes;
    }
}
