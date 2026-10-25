<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * Something that can carry a message away.
 *
 * Two implementations ship: SmtpTransport, which talks to a real server, and
 * NullTransport, which records the message and sends nothing. Tests and local
 * development use the second; nothing above this interface knows which it has.
 *
 * `send()` either succeeds or throws SmtpException. It never returns false and
 * it never returns "probably" - the caller that wants a boolean is the
 * notification layer, and turning the exception into one is its job, because
 * it is the layer that also has to write the audit row saying so.
 */
interface Transport
{
    /** @throws SmtpException */
    public function send(Message $message): void;

    /** For logs and the admin's System page: "smtp", "null". */
    public function name(): string;
}
