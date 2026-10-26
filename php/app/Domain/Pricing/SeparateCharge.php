<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * A charge the customer is TOLD about and never charged inside a price: an
 * add-on line written "Pickup | 1000 | separate" (or "info").
 *
 * OWNER DECISION, 2026-10-26 - "the rates shown everywhere should only show
 * the actual rate, not the pickup charges included. At the bottom just add a
 * drill down and there show 1000 pickup charges". So: owner: rates show the
 * courier rate only; the pickup charge is shown under the quote, never added.
 *
 * It is deliberately NOT an Addon. Every reader of Engine::addonsList() - the
 * tiles, the rates board and its caption, SiteFacts, resolveAddons(), the
 * quote log, the admin test-price panel - adds the "on" lines to a price or
 * offers the "off" lines as a tick box. A separate charge must do neither, and
 * keeping it out of that list means none of those readers can add it by
 * accident: it is reachable only through Engine::separateCharges(), whose
 * readers print it and never sum it. pricing.js `separateCharges()` is the
 * twin and returns the same three fields.
 */
final class SeparateCharge
{
    public function __construct(
        /** "a" + the line's index among the non-empty add-on lines, the same id scheme Addon uses. */
        public readonly string $id,
        public readonly string $label,
        public readonly float $amount,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'amount' => $this->amount];
    }
}
