<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * The transport that actually reaches a mail server.
 *
 * A thin wrapper on purpose: SmtpClient owns the protocol and knows nothing
 * about this application, and this class owns the one decision the protocol
 * should not make - what a failure looks like to the layer above.
 */
final class SmtpTransport implements Transport
{
    public function __construct(private readonly SmtpSettings $settings)
    {
    }

    public function send(Message $message): void
    {
        (new SmtpClient($this->settings))->send($message);
    }

    public function name(): string
    {
        return 'smtp';
    }

    /** Where messages are going, for the admin's System page. Never the credentials. */
    public function describe(): string
    {
        return $this->settings->host . ':' . $this->settings->port
            . ' (' . $this->settings->secure . ($this->settings->wantsAuth() ? ', authenticated' : '') . ')';
    }
}
