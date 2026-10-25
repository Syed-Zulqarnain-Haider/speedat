<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops\Support;

use Speedat\Domain\Notify\Alerts;
use Speedat\Domain\Notify\Notifier;
use Speedat\Vendor\Smtp\Message;
use Speedat\Vendor\Smtp\SmtpException;
use Speedat\Vendor\Smtp\Transport;

/**
 * The office's mailbox, for a test: every message the cron sends is kept
 * instead of delivered, and nothing leaves the machine.
 *
 * IT IS A TRANSPORT, NOT A STUBBED NOTIFIER. The Ops tests used to inject a
 * fake Ops\Contract\Notifier, which meant the alert text was asserted against
 * an object that had never built a message. This sits at the bottom of the real
 * path instead - Notify\Alerts, then Notify\Notifier, then Vendor\Smtp\Message -
 * so the subject the test reads is the subject a mail server would have been
 * handed, prefix, encoding, recipient and all. When the interface between
 * alerts and mail changes, these tests fail, which is the entire point of not
 * stubbing it.
 *
 * `$accept = false` reproduces a mail server that refuses the message. THE
 * ATTEMPT IS RECORDED BEFORE THE THROW, on purpose: the behaviour worth proving
 * is that a refused alert still uses up the day's slot, and that assertion needs
 * to be able to say "it was attempted once, and not attempted again today".
 */
final class RecordingOffice implements Transport
{
    /** Where the alerts go. A .test address, so a leak cannot reach anybody. */
    public const OFFICE = 'office@speedat.test';

    private const FROM = 'noreply@speedat.test';

    /** @var list<Message> */
    private array $messages = [];

    public function __construct(private readonly bool $accept = true)
    {
    }

    /* ------------------------------------------------- the transport itself */

    public function send(Message $message): void
    {
        $this->messages[] = $message;

        if (!$this->accept) {
            throw SmtpException::connect('smtp.test', 587, 'Connection refused');
        }
    }

    public function name(): string
    {
        return 'recording';
    }

    /* ------------------------------------------------------------- wiring */

    /** The real notifier, over this transport. */
    public function notifier(): Notifier
    {
        return new Notifier($this, self::OFFICE, self::FROM, 'Speedat International', 'speedat.test');
    }

    /**
     * The real alerts, over this transport, deduplicating in the app_settings
     * row the test owns rather than the live `alerts` row.
     */
    public function alerts(string $settingsKey): Alerts
    {
        return new Alerts($this->notifier(), $settingsKey);
    }

    /* -------------------------------------------------------- what went out */

    public function count(): int
    {
        return count($this->messages);
    }

    public function lastSubject(): string
    {
        $last = $this->last();

        return $last === null ? '' : $last->subject;
    }

    public function lastText(): string
    {
        $last = $this->last();

        return $last === null ? '' : $last->text;
    }

    /**
     * Everything sent, flattened - for a test that asserts about the whole
     * batch rather than the last message.
     *
     * @return list<array{subject:string,text:string}>
     */
    public function sent(): array
    {
        $out = [];
        foreach ($this->messages as $message) {
            $out[] = ['subject' => $message->subject, 'text' => $message->text];
        }

        return $out;
    }

    private function last(): ?Message
    {
        return $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
    }
}
