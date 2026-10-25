<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops\Contract;

use Speedat\Domain\Ops\Hold;

/**
 * Where the price-hold state comes from.
 *
 * Read-only on purpose: Ops reports the hold and alerts on one that has been
 * left on, and must never be able to turn it on or off. Only an admin does
 * that, through Domain\SiteDoc, with an audit row against their name.
 *
 * THE BINDING IS NO LONGER A STAND-IN. HoldSetting reads
 * Speedat\Domain\SiteDoc\Hold, the real repository, so the endpoint that answers
 * "are prices showing" and the shop front that decides whether to show them read
 * the same row through the same parser.
 *
 * The interface stays as the seam the tests inject through: "held for fourteen
 * hours as of this instant" is a state worth asserting exactly, and producing it
 * by writing the site's one real hold row is a test that switches the shop's
 * prices off to make its point.
 */
interface HoldSource
{
    public function current(): Hold;
}
