<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops\Contract;

use Speedat\Domain\Ops\LiveVersion;

/**
 * Where the live published version comes from.
 *
 * The one fact the operational surface needs about the site document: which
 * version is live, when it went live, and whether it is flagged live at all.
 * Ops never wants the document itself, so this interface does not offer it.
 *
 * THE BINDING IS NO LONGER A STAND-IN. VersionsTable reads
 * Speedat\Domain\SiteDoc\Repo, the real repository. It used to run its own SQL
 * against `versions` because SiteDoc was still in flight; it does not any more,
 * so there is one definition of "the live version" in the codebase.
 *
 * The interface stays because it is the seam the tests inject through. What they
 * cover is Cron's behaviour at a day boundary, at the staleness threshold, and
 * on a site that has never published anything - and a shared, seeded test
 * database cannot be made to say "nothing has ever been published" without
 * truncating the table every other suite is using.
 */
interface Versions
{
    /** The version the site is serving, or null when nothing has been published. */
    public function live(): ?LiveVersion;
}
