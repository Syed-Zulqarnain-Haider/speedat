<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * Where FirebaseToken gets the public keys it verifies signatures with.
 *
 * It exists so the verifier can be tested without a network: the test hands it
 * a source holding a key pair the test generated itself. In production the
 * source is GoogleCertificates.
 */
interface CertificateSource
{
    /**
     * Current signing certificates.
     *
     * @return array<string,string> key id ("kid") => X.509 certificate in PEM form
     *
     * @throws FirebaseTokenError when the certificates cannot be obtained at all
     */
    public function keys(): array;
}
