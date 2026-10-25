<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Domain\SiteDoc\SchemaIssue;

/**
 * The document a form asked to store is not a shape the site can hold.
 *
 * WHY THIS IS RAISED AT SAVE TIME AND NOT AT PUBLISH TIME.
 * `Repo::publishVersion()` runs `Schema::firstIssue()` on the RAW document
 * when the caller hands one in, and on the normalised one otherwise. The
 * admin never had a raw one to hand in: it publishes what is already stored
 * in the draft row, and `SiteData::fromArray()` has coerced that on the way
 * in - so the raw check was dead code for every publish the owner has ever
 * made, and the one refusal that did survive normalisation (a price larger
 * than the money cap) arrived as an `InvalidArgumentException` and became
 * "That could not be published... Try again in a moment", which is a sentence
 * about nothing the owner can act on, three steps after the typing that
 * caused it.
 *
 * So the check moved to where the raw document actually exists: the save.
 * `RatesController::store()` runs it on the array the form built, before the
 * write, and raises this. Nothing is stored, the editor says what to do, and
 * the owner is still looking at the box he typed it into.
 *
 * `$issue` carries the field path for the log. It never reaches the screen -
 * `Http\Admin\Notice` carries codes and never text, so that nothing a person
 * typed can be put into a URL and printed back at him.
 */
final class BadDocument extends \RuntimeException
{
    public function __construct(public readonly SchemaIssue $issue)
    {
        parent::__construct('refusing to store an invalid document: ' . $issue);
    }
}
