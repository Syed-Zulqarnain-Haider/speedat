<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A throwaway RSA key pair and self-signed certificate, generated in the test
 * run itself.
 *
 * NOTHING IS CHECKED IN. A private key in the repository - even a test one -
 * is a private key in every clone and every backup, and eventually somebody
 * copies it somewhere it matters. Generating one costs a few hundred
 * milliseconds, once, and the tests then sign their own Firebase tokens with a
 * key that never existed before the run and does not exist after it.
 *
 * On Windows, PHP cannot find openssl.cnf unless it is told where it is, so the
 * usual places are tried before giving up.
 */
final class RsaKeyPair
{
    private static ?self $shared = null;

    private function __construct(
        public readonly OpenSSLAsymmetricKey $privateKey,
        public readonly string $certificatePem,
    ) {
    }

    /** One pair for the whole run - generating RSA keys is not free. */
    public static function shared(): self
    {
        return self::$shared ??= self::generate();
    }

    public static function generate(): self
    {
        $arguments = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
        ];

        $config = self::locateOpenSslConfig();

        if ($config !== null) {
            $arguments['config'] = $config;
        }

        $key = @openssl_pkey_new($arguments);

        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new() failed: ' . (openssl_error_string() ?: 'no detail'));
        }

        $csr = @openssl_csr_new(['commonName' => 'speedat-test'], $key, $arguments);

        if ($csr === false) {
            throw new RuntimeException('openssl_csr_new() failed: ' . (openssl_error_string() ?: 'no detail'));
        }

        $certificate = @openssl_csr_sign($csr, null, $key, 1, $arguments);

        if ($certificate === false) {
            throw new RuntimeException('openssl_csr_sign() failed: ' . (openssl_error_string() ?: 'no detail'));
        }

        $pem = '';

        if (!openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('openssl_x509_export() failed');
        }

        return new self($key, $pem);
    }

    /** Can this host generate a key at all? Tests skip rather than fail if not. */
    public static function isAvailable(): bool
    {
        try {
            self::shared();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Sign a JWT the way Google's token service does - RS256 over
     * base64url(header) "." base64url(payload).
     *
     * @param array<string,mixed> $header
     * @param array<string,mixed> $payload
     */
    public function sign(array $header, array $payload): string
    {
        $signingInput = self::encodeSegment($header) . '.' . self::encodeSegment($payload);
        $signature    = '';

        if (!openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl_sign() failed');
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    /** @param array<string,mixed> $data */
    public static function encodeSegment(array $data): string
    {
        return self::base64Url((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function locateOpenSslConfig(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            // Windows builds ship it beside php.exe; PHP_BINDIR is the
            // compile-time path and is wrong for a portable install.
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            PHP_BINDIR . '/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
