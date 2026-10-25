<?php

declare(strict_types=1);

namespace Speedat\Domain\Notify;

use Speedat\Core\Audit;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Str;
use Speedat\Vendor\Smtp\Message;
use Speedat\Vendor\Smtp\NullTransport;
use Speedat\Vendor\Smtp\SmtpException;
use Speedat\Vendor\Smtp\SmtpSettings;
use Speedat\Vendor\Smtp\SmtpTransport;
use Speedat\Vendor\Smtp\Transport;

/**
 * Outbound notifications to the office. Port of src/lib/notify.ts, with
 * Postmark's HTTP API replaced by the vendored SMTP client.
 *
 * TWO RULES CARRIED OVER FROM THE TYPESCRIPT, BOTH LOAD-BEARING:
 *
 *  1. IT NEVER THROWS. A lead is saved whether or not the office hears about
 *     it. notify.ts returns false on every failure and so does this.
 *  2. A FAILURE IS NEVER SILENT. Every false leaves a log line and an audit
 *     row - notify_skipped when nothing is configured, notify_failed when the
 *     server refused - so "we never got the email" has an answer in the admin
 *     rather than a shrug.
 *
 * WHY NOT mail(). Hostinger caps mail() at 10 a minute and 100 a rolling day
 * and then blocks silently (docs/hostinger-constraints.md section 9). A
 * silently blocked notification is exactly rule 2 in reverse, so mail() is not
 * one of the transports here: a configuration asking for it is logged and
 * downgraded to the null transport, which at least says out loud that nothing
 * was sent.
 *
 * WHAT IS NEVER LOGGED: the credentials (they never leave SmtpSettings) and the
 * body (it carries a customer's name, number and what they are shipping). The
 * subject and a masked recipient are enough to trace a message.
 */
final class Notifier
{
    public function __construct(
        private readonly Transport $transport,
        /** The office address; '' means nothing is configured. */
        private readonly string $office,
        private readonly string $fromAddress,
        private readonly string $fromName = '',
        /** Domain for EHLO and Message-ID; the site's host. */
        private readonly string $domain = '',
        /**
         * Whether the transport puts a message in somebody's inbox. Only SMTP
         * does; `log` and `null` accept a message and drop it. Defaults to true
         * so a test that hands in a recording transport is a delivering one.
         */
        private readonly bool $delivers = true,
        /** Why it does not deliver, in words for the System page. '' when it does. */
        private readonly string $undeliveredReason = '',
    ) {
    }

    /**
     * Build from config. `mail.transport` is smtp | log | null; anything else,
     * including the legacy "mail", is downgraded to the null transport with a
     * warning rather than quietly using a capped sender.
     */
    public static function fromConfig(): self
    {
        $wanted = strtolower(Config::str('mail.transport', 'log'));
        $from   = Config::str('mail.from');
        $host   = (string) (parse_url(Config::str('app.url'), PHP_URL_HOST) ?: '');

        $transport = null;
        $reason    = '';

        if ($wanted === 'smtp') {
            try {
                $transport = new SmtpTransport(new SmtpSettings(
                    host: Config::str('mail.smtp.host'),
                    port: Config::int('mail.smtp.port', 587),
                    secure: strtolower(Config::str('mail.smtp.secure', 'tls')),
                    username: Config::str('mail.smtp.user'),
                    password: Config::str('mail.smtp.pass'),
                    timeout: max(1, min(30, Config::int('mail.smtp.timeout', 10))),
                    ehloDomain: $host !== '' ? $host : 'localhost',
                ));
            } catch (SmtpException $e) {
                // A bad mailbox configuration must not take the site down; it
                // takes the notifications down, loudly.
                Log::error('notify_smtp_misconfigured', ['reason' => $e->getMessage(), 'stage' => $e->stage]);
                // SmtpSettings' messages name the field, never its value, so
                // this is safe to show an admin.
                $reason = 'the SMTP settings were refused: ' . $e->getMessage();
            }
        } elseif ($wanted !== 'log' && $wanted !== 'null' && $wanted !== '') {
            Log::warn('notify_transport_refused', [
                'wanted' => $wanted,
                'reason' => 'only smtp, log and null are supported; mail() is capped on this host',
            ]);
            $reason = 'mail.transport "' . Str::limit($wanted, 20) . '" is not supported - only smtp sends';
        } elseif ($wanted === 'null') {
            $reason = 'mail.transport is null - nothing is sent';
        } else {
            $reason = 'mail.transport is log - messages are written to the log, not sent';
        }

        $transport ??= new NullTransport(
            $wanted === 'null'
                ? null
                : static function (string $subject, int $bytes, int $recipients): void {
                    // Subject and size only. Never the body.
                    Log::info('notify_logged', ['subject' => $subject, 'bytes' => $bytes, 'recipients' => $recipients]);
                }
        );

        return new self(
            transport: $transport,
            office: Config::str('mail.office'),
            fromAddress: $from,
            fromName: Config::str('mail.from_name', 'Speedat International'),
            domain: $host,
            // Decided by what was BUILT, not by what was asked for: an `smtp`
            // whose settings failed is a NullTransport, and it delivers nothing.
            delivers: $transport instanceof SmtpTransport,
            undeliveredReason: $transport instanceof SmtpTransport ? '' : $reason,
        );
    }

    /**
     * Whether a message would actually go anywhere. The admin's System page and
     * the inbox's "alerts not configured" banner both read this.
     *
     * THE TRANSPORT COUNTS. This used to check the two addresses and nothing
     * else, so with `mail.transport` at `log` (the live state until SMTP is set
     * up) or an `smtp` whose settings were refused, the System page showed a
     * green "sending through null", the inbox banner was hidden, and every
     * lead and alert "succeeded" into the log while the office got nothing
     * (found 2026-10-25). An address with nowhere to send it is not configured.
     */
    public function configured(): bool
    {
        return $this->hasAddresses() && $this->delivers;
    }

    /** Why configured() is false, in words an admin can act on. '' when it is true. */
    public function notConfiguredReason(): string
    {
        if ($this->office === '') {
            return 'no office address (mail.office)';
        }
        if ($this->fromAddress === '') {
            return 'no sender address (mail.from)';
        }

        return $this->delivers ? '' : $this->undeliveredReason;
    }

    public function transportName(): string
    {
        return $this->transport->name();
    }

    /**
     * Send to the office. True when the transport accepted it. Never throws.
     *
     * @param string|null $replyTo the customer's address, when a lead has one,
     *                             so the office can answer from its inbox
     */
    public function office(Notification $notification, ?string $replyTo = null): bool
    {
        // The addresses only, not configured(): in log mode the message must
        // still reach the transport, because the log line it writes is the
        // only trace of it. to() records that it was not delivered.
        if (!$this->hasAddresses()) {
            Log::warn('notify_not_configured', ['subject' => $notification->subject]);
            Audit::log('system', 'notify_skipped', [
                'subject' => Str::limit($notification->subject, 190),
                'reason'  => 'not configured',
            ]);

            return false;
        }

        return $this->to($this->office, $notification, $replyTo);
    }

    /**
     * Send to a named recipient. The office path goes through here too, so
     * there is exactly ONE place in the application that hands a message to a
     * transport - which is the whole point of this class existing at all.
     *
     * An unusable recipient or an unset sender is the same "nothing was sent,
     * and here is the row that says so" outcome as an unconfigured office: a
     * message that silently goes nowhere is the failure mode this package was
     * written to make impossible.
     */
    public function to(string $recipient, Notification $notification, ?string $replyTo = null): bool
    {
        $recipient = trim($recipient);

        if ($recipient === '' || !Str::isEmail($recipient) || $this->fromAddress === '') {
            Log::warn('notify_not_configured', [
                'subject' => $notification->subject,
                'reason'  => $this->fromAddress === '' ? 'no sender address' : 'bad recipient',
            ]);
            Audit::log('system', 'notify_skipped', [
                'subject' => Str::limit($notification->subject, 190),
                'reason'  => 'not configured',
            ]);

            return false;
        }

        try {
            $message = Message::create(
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                to: $recipient,
                subject: $notification->subject,
                text: $notification->text,
                html: $notification->htmlBody(),
                replyTo: $replyTo !== null && Str::isEmail($replyTo) ? $replyTo : null,
                tag: $notification->tag,
                messageIdDomain: $this->domain !== '' ? $this->domain : null,
            );

            $this->transport->send($message);

            if (!$this->delivers) {
                // Accepted and dropped. Rule 2: a message that went nowhere
                // leaves a row saying so, and the caller hears false - a cron
                // summary reading "office told" over a log transport is the
                // silence this class exists to prevent.
                Log::warn('notify_not_delivered', [
                    'subject'   => $notification->subject,
                    'transport' => $this->transport->name(),
                ]);
                Audit::log('system', 'notify_skipped', [
                    'subject' => Str::limit($notification->subject, 190),
                    'reason'  => 'transport ' . $this->transport->name(),
                ]);

                return false;
            }

            return true;
        } catch (SmtpException $e) {
            // stage and code, not the server's prose: see SmtpException.
            Log::error('notify_failed', [
                'subject' => $notification->subject,
                'to'      => Str::maskEmail($recipient),
                'stage'   => $e->stage,
                'smtp'    => $e->replyCode,
                'reason'  => $e->getMessage(),
            ]);
            Audit::log('system', 'notify_failed', [
                'subject' => Str::limit($notification->subject, 190),
                'stage'   => $e->stage,
                'code'    => $e->replyCode,
            ]);

            return false;
        } catch (\Throwable $e) {
            // Anything else - a DNS resolver blowing up, a stream wrapper
            // missing - is still not allowed to reach the caller.
            Log::exception('notify_failed', $e, ['subject' => $notification->subject]);
            Audit::log('system', 'notify_failed', ['subject' => Str::limit($notification->subject, 190)]);

            return false;
        }
    }

    /** Both addresses are set. The half of configured() that does not ask about the transport. */
    private function hasAddresses(): bool
    {
        return $this->office !== '' && $this->fromAddress !== '';
    }
}
