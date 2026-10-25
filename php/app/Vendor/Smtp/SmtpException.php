<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * Something went wrong handing a message to a mail server.
 *
 * Every failure in this package throws one of these, with a stage that names
 * where it happened ("connect", "greeting", "auth", "rcpt", ...) and, when the
 * server said something, the reply code it said it with. The notification layer
 * catches it, logs the stage and the code, and carries on - a lead is saved
 * even when the mail server is down.
 *
 * THE MESSAGE NEVER CONTAINS A CREDENTIAL. The AUTH exchange is base64 of the
 * username and the password; the reply to it is recorded as a code and a stage
 * and nothing else, because an exception message ends up in a log file, and a
 * log file ends up in a support ticket.
 */
final class SmtpException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $stage,
        /**
         * The SMTP reply code the server gave, or 0 when it never replied.
         *
         * Not called `code`: Exception already has a non-readonly $code and a
         * subclass may not redeclare it as readonly. It is passed to the parent
         * as well, so getCode() agrees with this.
         */
        public readonly int $replyCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $replyCode, $previous);
    }

    public static function connect(string $host, int $port, string $error): self
    {
        // The host and port are ours, not the user's, so they are safe to name;
        // knowing which of the two hosts failed is the whole value of the line.
        return new self(
            sprintf('Could not reach the mail server at %s:%d (%s).', $host, $port, $error),
            'connect',
        );
    }

    public static function tls(string $stage): self
    {
        return new self('The mail server would not negotiate TLS.', $stage);
    }

    public static function timeout(string $stage): self
    {
        return new self(sprintf('The mail server stopped responding during %s.', $stage), $stage);
    }

    /** The server answered, but not with a code we can continue from. */
    public static function refused(string $stage, int $code, string $reply): self
    {
        return new self(
            sprintf('The mail server refused %s: %d %s', $stage, $code, self::trim($reply)),
            $stage,
            $code,
        );
    }

    /**
     * AUTH failed. The reply text is deliberately dropped: some servers echo
     * the username back in it.
     */
    public static function auth(int $code): self
    {
        return new self('The mail server rejected the credentials.', 'auth', $code);
    }

    public static function unsupported(string $what): self
    {
        return new self($what, 'capability');
    }

    public static function write(string $stage): self
    {
        return new self('The connection to the mail server closed while sending.', $stage);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 'validate');
    }

    /** One line, bounded: a server is free to answer with a novel. */
    private static function trim(string $reply): string
    {
        $reply = preg_replace('/\s+/', ' ', trim($reply)) ?? '';

        return strlen($reply) > 200 ? substr($reply, 0, 197) . '...' : $reply;
    }
}
