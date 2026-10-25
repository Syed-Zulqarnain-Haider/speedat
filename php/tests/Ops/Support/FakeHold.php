<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops\Support;

use Speedat\Domain\Ops\Contract\HoldSource;
use Speedat\Domain\Ops\Hold;

/** A price hold the test chooses. */
final class FakeHold implements HoldSource
{
    public function __construct(private readonly Hold $hold)
    {
    }

    public static function off(): self
    {
        return new self(Hold::off());
    }

    public static function onSince(string $utc, string $by = 'owner@speedat.test'): self
    {
        return new self(new Hold(true, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), $by));
    }

    public function current(): Hold
    {
        return $this->hold;
    }
}
