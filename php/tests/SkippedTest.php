<?php

declare(strict_types=1);

namespace Speedat\Tests;

/**
 * A test that chose not to run - no database, a missing extension - and said
 * why. Reported separately from a pass, so a suite that is quietly skipping
 * half of itself cannot look green.
 */
final class SkippedTest extends \RuntimeException
{
}
