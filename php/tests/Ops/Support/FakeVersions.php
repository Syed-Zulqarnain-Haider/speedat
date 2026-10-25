<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops\Support;

use Speedat\Domain\Ops\Contract\Versions;
use Speedat\Domain\Ops\LiveVersion;

/**
 * A live version the test chooses. Domain\SiteDoc's real repository is still
 * being written; this is what the narrow interface is for.
 */
final class FakeVersions implements Versions
{
    public function __construct(private readonly ?LiveVersion $version)
    {
    }

    public static function none(): self
    {
        return new self(null);
    }

    public static function publishedAt(string $utc, int $version = 7, bool $live = true): self
    {
        return new self(new LiveVersion($version, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), $live));
    }

    public function live(): ?LiveVersion
    {
        return $this->version;
    }
}
