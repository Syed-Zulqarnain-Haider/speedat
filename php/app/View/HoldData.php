<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\SiteDoc\Countries;

/**
 * The blob the hold panel reads (php/docs/assets.md section 6, "The hold
 * panel"): names only.
 *
 * While prices are on hold the rate document must not reach the browser, so
 * this carries no settings, no rates and no currency - four scalars and a list
 * of `{id, name}`. `maxKg` is the one number, and it is a limit rather than a
 * price: the weight field's placeholder reads "1 to 25, or more for cargo".
 *
 * A separate class, not a mode of `CalculatorData`, because "no price reaches
 * the browser" must be true by construction and not by remembering a flag.
 */
final class HoldData
{
    public const MOUNT_ID = 'speedat-hold';

    public function __construct(
        private readonly Doc $doc,
        private readonly string $basePath = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $destinations = [];
        $flags        = [];
        foreach ($this->doc->activeAlpha() as $dest) {
            $destinations[]   = ['id' => $dest->id, 'name' => $dest->name];
            $flags[$dest->id] = Countries::flagCode($dest->name, $dest->id);
        }

        return [
            'companyName'  => $this->doc->company()->name,
            'whatsapp'     => $this->doc->company()->whatsapp,
            'message'      => $this->doc->holdMessage(),
            'maxKg'        => $this->doc->settings()->maxKg,
            'destinations' => $destinations,
            // Carried because assets.md asks for them; today calculator.js's
            // HoldPanel reads its flags from the module globals the calculator
            // branch sets, and a hold page has no calculator, so the chosen
            // country shows the two-letter badge. Harmless either way - a
            // country name is already in this blob.
            'flagsBase'    => $this->basePath . '/assets/flags/',
            'flags'        => $flags === [] ? new \stdClass() : $flags,
        ];
    }

    /** Encoded exactly as CalculatorData::json() encodes its own; see the note there. */
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

    /** @return list<Destination> */
    public function destinations(): array
    {
        return $this->doc->activeAlpha();
    }
}
