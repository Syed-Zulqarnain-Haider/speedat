<?php

declare(strict_types=1);

namespace Speedat\Core;

use Speedat\Domain\Notify\Notification;
use Speedat\Domain\Notify\Notifier;

/**
 * Outbound email, as the rest of the application still names it.
 *
 * THIS CLASS NO LONGER SENDS ANYTHING ITSELF. It is a thin facade over
 * Speedat\Domain\Notify\Notifier, which owns the transport, the MIME encoding
 * and the audit trail. It survives only because Core\App::mail() is the handle
 * the controllers were written against; every byte that leaves this site leaves
 * through Domain\Notify and the hand-written SMTP client in app/Vendor/Smtp.
 *
 * WHY mail() IS GONE. It used to be the default here, on the reasoning that
 * shared hosting has it and it costs nothing. Hostinger caps mail() at TEN a
 * minute and ONE HUNDRED a rolling day and then blocks it SILENTLY
 * (docs/hostinger-constraints.md section 9). A notification that is dropped
 * without an error is the one failure this project cannot have: the office's
 * only warning that nobody has published rates for a fortnight would be the
 * message that went missing. There is therefore no `mail` transport any more -
 * not as a fallback, not as an option. A configuration asking for it is logged
 * and downgraded to the null transport, which at least says out loud that
 * nothing was sent (see Notifier::fromConfig).
 *
 * The transports are `smtp` (production) and `log` (local development and
 * tests: the message is recorded, its subject and size are logged, and nothing
 * leaves the machine).
 *
 * It never throws and never blocks a user action: a quote must be saved even
 * when the mail server is down, and the log line plus the audit row are what
 * make the failure visible instead of silent.
 *
 * NOTE ON LAYERING. Core depending on Domain is the wrong direction, and this
 * is the one place it happens. It is deliberate and it is temporary: the
 * alternative is a second copy of the transport wiring, the MIME encoder and
 * the failure bookkeeping living in Core, and two senders that can disagree
 * about whether a message went out is exactly the bug this consolidation was
 * for. Nothing inside Core uses this class; when the controllers name
 * Domain\Notify directly, both this file and Core\App::mail() go.
 */
final class Mail
{
    public function __construct(private readonly Notifier $notifier)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Notifier::fromConfig());
    }

    /** Whether a message would actually go anywhere. The admin's System page reports this. */
    public function configured(): bool
    {
        return $this->notifier->configured();
    }

    /** "smtp" or "null", for the admin's System page. Never the credentials. */
    public function transportName(): string
    {
        return $this->notifier->transportName();
    }

    /** The office address every operational notification goes to. */
    public static function office(): string
    {
        return Config::str('mail.office');
    }

    /** Send to the office, if one is configured. Returns true when it was accepted. */
    public function toOffice(string $subject, string $text, ?string $html = null): bool
    {
        return $this->notifier->office(new Notification(Str::squish($subject), $text, $html));
    }

    /**
     * Send to a named recipient. Returns true when the transport accepted the
     * message. Never throws.
     *
     * Header hygiene is not done here: Vendor\Smtp\Message refuses a CR or an
     * LF anywhere in a recipient, a subject, a display name or a reply-to,
     * rather than stripping it, and Notifier turns that refusal into a false
     * with a log line. One implementation of the rule, in the class that builds
     * the headers.
     */
    public function send(
        string $to,
        string $subject,
        string $text,
        ?string $html = null,
        ?string $replyTo = null,
    ): bool {
        return $this->notifier->to($to, new Notification(Str::squish($subject), $text, $html), $replyTo);
    }
}
