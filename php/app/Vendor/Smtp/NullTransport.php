<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * The transport for local development and for tests: it accepts the message,
 * remembers it, and sends nothing.
 *
 * It keeps the messages in memory so a test can assert what would have gone
 * out, and - when a logger is handed to it - writes one line per message with
 * the subject and the size. NEVER THE BODY. A notification body carries a
 * customer's name, phone number and what they are shipping; a development log
 * that collects those is a data leak with a friendly name.
 *
 * `$failWith` exists so a test can make sending fail without a mail server:
 * the notification layer's behaviour when the transport throws is the part
 * worth covering, and it should not need a broken socket to reach it.
 */
final class NullTransport implements Transport
{
    /** @var list<Message> */
    private array $sent = [];

    /** @param (\Closure(string $subject, int $bytes, int $recipients): void)|null $logger */
    public function __construct(
        private readonly ?\Closure $logger = null,
        private readonly ?SmtpException $failWith = null,
    ) {
    }

    public function send(Message $message): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->sent[] = $message;

        if ($this->logger !== null) {
            ($this->logger)($message->subject, strlen($message->data()), count($message->to));
        }
    }

    public function name(): string
    {
        return 'null';
    }

    /** @return list<Message> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function last(): ?Message
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function forget(): void
    {
        $this->sent = [];
    }
}
