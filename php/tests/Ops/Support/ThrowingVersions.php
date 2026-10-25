<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops\Support;

use Speedat\Domain\Ops\Contract\Versions;
use Speedat\Domain\Ops\LiveVersion;

/**
 * A version source that fails, so a test can prove the cron records the
 * failure, carries on with the other jobs, and still comes back non-zero.
 */
final class ThrowingVersions implements Versions
{
    public function __construct(private readonly string $message = 'the version table is unavailable')
    {
    }

    public function live(): ?LiveVersion
    {
        throw new \RuntimeException($this->message);
    }
}
