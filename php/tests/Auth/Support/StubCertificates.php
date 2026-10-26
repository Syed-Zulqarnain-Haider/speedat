<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth\Support;

use Speedat\Domain\Auth\CertificateSource;
use Speedat\Domain\Auth\CertificatesUnavailable;

/**
 * The certificates FirebaseToken should trust, decided by the test. This is the
 * whole reason CertificateSource is an interface: the verifier can be exercised
 * against a key pair made in the test run, with no network anywhere near it.
 */
final class StubCertificates implements CertificateSource
{
    public int $calls = 0;

    /** How often FirebaseToken asked for a refresh (an unknown kid). */
    public int $refreshes = 0;

    /**
     * @param array<string,string>      $keys      kid => PEM
     * @param array<string,string>|null $refreshed what refresh() hands back; null means "the same set"
     */
    public function __construct(
        private readonly array $keys,
        private readonly bool $fail = false,
        private readonly ?array $refreshed = null,
    ) {
    }

    public function keys(): array
    {
        $this->calls++;

        if ($this->fail) {
            throw new CertificatesUnavailable('Stub certificate source is pretending Google is down');
        }

        return $this->keys;
    }

    public function refresh(): array
    {
        $this->refreshes++;

        return $this->refreshed ?? $this->keys();
    }
}
