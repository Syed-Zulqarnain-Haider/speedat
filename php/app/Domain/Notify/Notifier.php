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
            }
        } elseif ($wanted !== 'log' && $wanted !== 'null' && $wanted !== '') {
            Log::warn('notify_transport_refused', [
                'wanted' => $wanted,
                'reason' => 'only smtp, log and null are supported; mail() is capped on this host',
            ]);
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
        );
    }

    /** Whether a message would actually go anywhere. The admin's System page reports this. */
    public function configured(): bool
    {
        return $this->office !== '' && $this->fromAddress !== '';
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
        if (!$this->configured()) {
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
}
