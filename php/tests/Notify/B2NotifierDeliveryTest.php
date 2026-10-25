<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Domain\Notify\Notification;
use Speedat\Domain\Notify\Notifier;
use Speedat\Tests\TestCase;
use Speedat\Vendor\Smtp\NullTransport;

/**
 * "Configured" means a message reaches an inbox, not that two addresses are set.
 *
 * With `mail.transport` at `log` (the live state until SMTP is set up), or an
 * `smtp` whose settings were refused, the System page used to show a green
 * "sending through null", the inbox's "alerts not configured" banner was
 * hidden, every send returned true with no notify_skipped row, and the cron
 * said "office told" - while the office got nothing (found 2026-10-25).
 */
final class B2NotifierDeliveryTest extends TestCase
{
    private const OFFICE = 'office@speedat.test';
    private const FROM   = 'noreply@speedat.test';

    /** @var array<string,string> */
    private array $saved = [];

    public function setUp(): void
    {
        foreach (['mail.transport', 'mail.office', 'mail.from', 'mail.smtp.host'] as $key) {
            $this->saved[$key] = Config::str($key);
        }

        Config::set('mail.office', self::OFFICE);
        Config::set('mail.from', self::FROM);
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            Config::set($key, $value);
        }
    }

    public function testTheLogTransportIsNotConfiguredAndSaysWhy(): void
    {
        Config::set('mail.transport', 'log');
        $notifier = Notifier::fromConfig();

        $this->assertFalse($notifier->configured(), 'both addresses are set, and still nothing is sent');
        $this->assertStringContains('mail.transport is log', $notifier->notConfiguredReason());
    }

    public function testAnSmtpTransportWhoseSettingsFailedIsNotConfigured(): void
    {
        Config::set('mail.transport', 'smtp');
        Config::set('mail.smtp.host', '');
        $notifier = Notifier::fromConfig();

        $this->assertSame('null', $notifier->transportName(), 'it fell back to sending nothing');
        $this->assertFalse($notifier->configured(), 'and a fallback is not a configuration');
        $this->assertStringContains('SMTP settings were refused', $notifier->notConfiguredReason());
    }

    public function testAWorkingSmtpTransportIsConfigured(): void
    {
        Config::set('mail.transport', 'smtp');
        Config::set('mail.smtp.host', 'smtp.example.invalid');
        $notifier = Notifier::fromConfig();

        $this->assertTrue($notifier->configured(), 'building the transport opens no socket, so this is offline');
        $this->assertSame('', $notifier->notConfiguredReason());
    }

    public function testAMissingAddressIsNamedFirst(): void
    {
        Config::set('mail.transport', 'log');
        Config::set('mail.office', '');

        $this->assertSame('no office address (mail.office)', Notifier::fromConfig()->notConfiguredReason());
    }

    /**
     * Log mode still hands the message to the transport - the log line is its
     * only trace - but the caller hears false and the audit says why.
     */
    public function testASendThroughANonDeliveringTransportIsFalseAndRecorded(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable, so the audit row cannot be counted');
        }

        $transport = new NullTransport();
        $notifier  = new Notifier(
            $transport,
            self::OFFICE,
            self::FROM,
            'Speedat International',
            'speedat.test',
            delivers: false,
            undeliveredReason: 'mail.transport is log - messages are written to the log, not sent',
        );

        $before = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_skipped'], 0);

        $this->assertFalse($notifier->office(new Notification('B2 delivery check', 'body')));
        $this->assertSame(1, $transport->count(), 'the transport still saw it, so log mode logs it');
        $this->assertSame(
            $before + 1,
            (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_skipped'], 0),
            'a message that went nowhere leaves a row saying so',
        );
    }

    /** A transport handed in directly is a delivering one - AlertsTest and the cron tests rely on it. */
    public function testADirectlyBuiltNotifierStillDelivers(): void
    {
        $transport = new NullTransport();
        $notifier  = new Notifier($transport, self::OFFICE, self::FROM, 'Speedat International', 'speedat.test');

        $this->assertTrue($notifier->configured());
        $this->assertTrue($notifier->to(self::OFFICE, new Notification('B2 direct', 'body')));
    }
}
