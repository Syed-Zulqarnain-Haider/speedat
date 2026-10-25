<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\SiteDoc\Countries;

/**
 * The JSON blob `public/assets/js/calculator.js` reads out of
 * `<script type="application/json" id="speedat-calculator">`, exactly as
 * php/docs/assets.md section 6 specifies it.
 *
 * Everything in it is public by construction: it is the PUBLISHED document and
 * nothing else - no draft, no admin field, no lead. Every number a visitor can
 * see is derived from it in the browser, so anything that must not be seen
 * must not be here. That is why `HoldData` exists as a separate class rather
 * than a flag on this one: under hold this blob is not rendered at all.
 *
 * The tile's "from PKR ..." is deliberately NOT in the blob. calculator.js
 * computes it with the same engine call the third tap makes, so the tile and
 * the price can never disagree; `Copy::fromPrice()` computes the same figure
 * on the server for the headline and the board.
 */
final class CalculatorData
{
    public const MOUNT_ID = 'speedat-calculator';

    /** The weight buttons on the quick view, in kilograms; only those the rate card can price are shown. */
    private const KG_BUTTONS = [1, 2, 3, 5, 10, 15, 20, 25];

    public function __construct(
        private readonly Doc $doc,
        /** The application root, "" at a domain root and "/staging" in a subdirectory. */
        private readonly string $basePath = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $flags = [];
        foreach ($this->doc->active() as $dest) {
            $flags[$dest->id] = Countries::flagCode($dest->name, $dest->id);
        }

        return [
            'flagsBase' => $this->basePath . '/assets/flags/',
            'quotesUrl' => $this->basePath . '/api/quotes',
            'cities'    => Text::originCities($this->doc->company()->originCities),
            'flags'     => $flags === [] ? new \stdClass() : $flags,
            'site'      => $this->doc->toPublishedArray(),
        ];
    }

    /**
     * The encoded blob, ready to print raw inside the script tag.
     *
     * JSON_HEX_TAG is the flag that matters: it turns "<" and ">" into
     * < and >, so a destination the owner named "</script>" cannot
     * end the block. HEX_AMP, HEX_APOS and HEX_QUOT close the same door for an
     * HTML entity and for the two quote characters. INVALID_UTF8_SUBSTITUTE
     * means a broken byte from the database substitutes rather than making
     * json_encode() return false and the page print the word "false".
     *
     * This is the ONE place in the site templates where output is not passed
     * through e(): the encoder is the escaper, and e() would print the JSON as
     * visible text instead.
     */
    public function json(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }

    /**
     * The weight buttons the quick view shows: the eight round numbers, minus
     * any the rate card cannot price. Port of `KG_BUTTONS` filtered through
     * `weightOptions` in Calculator.tsx - the same list calculator.js builds,
     * so the server's first paint and the script's re-render have the same
     * buttons and the row does not reflow when the script boots.
     *
     * @return list<int>
     */
    public static function weightButtons(Doc $doc): array
    {
        $settings = $doc->settings();
        $maxKg    = $settings->maxKg;

        if ($settings->isGrid()) {
            $priced = Engine::gridWeights($settings);
        } else {
            $priced = [];
            for ($k = 0.5; $k <= 10.0; $k += 0.5) {
                $priced[] = $k;
            }
            for ($k = 11.0; $k <= 30.0; $k += 1.0) {
                $priced[] = $k;
            }
            $top = $maxKg > 0 ? min($maxKg, 200.0) : 100.0;
            for ($k = 35.0; $k <= $top; $k += 5.0) {
                $priced[] = $k;
            }
            if ($maxKg > 0) {
                $priced = array_values(array_filter($priced, static fn (float $x): bool => $x <= $maxKg));
            }
        }

        $out = [];
        foreach (self::KG_BUTTONS as $kg) {
            if (in_array((float) $kg, array_map(static fn (int|float $x): float => (float) $x, $priced), true)) {
                $out[] = $kg;
            }
        }

        return $out;
    }

    /**
     * The destinations that get their own tile, and the ones that only appear
     * in the "More countries" picker. Up to TILE_MAX destinations are all
     * tiles; beyond that the last cell becomes the picker, so the grid keeps
     * the same height whatever the count. Mirrors calculator.js.
     *
     * @return array{tiles:list<Destination>,more:bool}
     */
    public static function tiles(Doc $doc): array
    {
        $tileMax = 8;
        $active  = $doc->active();
        $more    = count($active) > $tileMax;

        return [
            'tiles' => $more ? array_slice($active, 0, $tileMax - 1) : $active,
            'more'  => $more,
        ];
    }
}
