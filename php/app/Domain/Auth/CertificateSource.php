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
     * @throws CertificatesUnavailable when the certificates cannot be obtained at all
     */
    public function keys(): array;

    /**
     * The certificates again, bypassing the cache's max-age - asked for by
     * FirebaseToken once, when a token names a key id the current set does
     * not have.
     *
     * WHY (2026-10-26). Google publishes a new key a while before it starts
     * signing with it, but a set cached up to max-age ago can still predate
     * the rotation, and every sign-in until that cache expired was refused as
     * "unknown key id". One refetch fixes that.
     *
     * THE SOURCE DECIDES WHETHER TO ACTUALLY FETCH. A stranger can post tokens
     * with made-up key ids all day, so the throttle cannot live in the caller:
     * GoogleCertificates refetches only when its set is more than five minutes
     * old and no fetch failed in the last minute, and otherwise hands back the
     * set it has. A source with nothing to refresh returns keys().
     *
     * @return array<string,string> key id => PEM
     *
     * @throws CertificatesUnavailable when the certificates cannot be obtained at all
     */
    public function refresh(): array;
}
