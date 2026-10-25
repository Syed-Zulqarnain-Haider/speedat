<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * Where to hand a message, and how to get in. Immutable and validated on
 * construction, so a misconfigured mailbox is a clear error at start-up rather
 * than a connection that hangs for ten seconds on every lead.
 *
 * `secure` has three values, and the difference matters on Hostinger:
 *   tls   connect in the clear on 587, then STARTTLS (the documented setup)
 *   ssl   connect with TLS already up, on 465 (implicit TLS)
 *   none  no encryption at all - local development only; AUTH is refused
 *         over it, because sending a password in the clear is not a thing
 *         this package will do quietly.
 */
final class SmtpSettings
{
    public const SECURE_STARTTLS = 'tls';
    public const SECURE_IMPLICIT = 'ssl';
    public const SECURE_NONE     = 'none';

    public function __construct(
        public readonly string $host,
        public readonly int $port = 587,
        public readonly string $secure = self::SECURE_STARTTLS,
        public readonly string $username = '',
        public readonly string $password = '',
        /** Seconds to wait for the connection and for every reply. */
        public readonly int $timeout = 10,
        /** The name given in EHLO. Falls back to the sender's domain. */
        public readonly string $ehloDomain = 'localhost',
        /** Off only for a local test server with a self-signed certificate. */
        public readonly bool $verifyPeer = true,
    ) {
        if (trim($host) === '') {
            throw SmtpException::invalid('No SMTP host is configured.');
        }
        if ($port < 1 || $port > 65535) {
            throw SmtpException::invalid('The SMTP port must be between 1 and 65535.');
        }
        if (!in_array($secure, [self::SECURE_STARTTLS, self::SECURE_IMPLICIT, self::SECURE_NONE], true)) {
            throw SmtpException::invalid('SMTP security must be one of tls, ssl or none.');
        }
        if ($timeout < 1 || $timeout > 120) {
            throw SmtpException::invalid('The SMTP timeout must be between 1 and 120 seconds.');
        }
        // A newline in either credential would be smuggled into the protocol as
        // an extra command. There is no legitimate reason for one.
        if (preg_match('/[\r\n\x00]/', $username . $password) === 1) {
            throw SmtpException::invalid('SMTP credentials may not contain control characters.');
        }
        if ($username !== '' && $secure === self::SECURE_NONE) {
            throw SmtpException::invalid('Refusing to send SMTP credentials over an unencrypted connection.');
        }
    }

    public function wantsAuth(): bool
    {
        return $this->username !== '';
    }

    /** The stream target: TLS from the first byte on 465, plain otherwise. */
    public function target(): string
    {
        return ($this->secure === self::SECURE_IMPLICIT ? 'ssl://' : 'tcp://')
            . $this->host . ':' . $this->port;
    }
}
