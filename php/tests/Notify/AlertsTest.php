<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Domain\Notify\Alerts;
use Speedat\Domain\Notify\Notification;
use Speedat\Domain\Notify\Notifier;
use Speedat\Domain\Notify\OfficeMessages;
use Speedat\Tests\TestCase;
use Speedat\Vendor\Smtp\NullTransport;
use Speedat\Vendor\Smtp\SmtpException;

/**
 * Alert deduplication, against the real app_settings row it is kept in.
 *
 * Time is frozen rather than waited for: "once per day" is measured against the
 * UTC calendar day, and the only way to test a day boundary without sleeping
 * until midnight is to move the clock.
 */
final class AlertsTest extends TestCase
{
    private const OFFICE = 'office@speedat.test';
    private const FROM   = 'noreply@speedat.test';

    private NullTransport $transport;

    private Alerts $alerts;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        Clock::freeze('2026-09-27 08:00:00 UTC');

        $this->transport = new NullTransport();
        $this->alerts    = new Alerts($this->notifier($this->transport));
        $this->alerts->reset();
    }

    public function tearDown(): void
    {
        Clock::freeze(null);

        if (Db::healthy()) {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [Alerts::SETTINGS_KEY]);
        }
    }

    /* ===================================================================== */
    /*  deduplication                                                        */
    /* ===================================================================== */

    public function testAnAlertGoesOutOnceAndThenNotAgainThatDay(): void
    {
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertSame(1, $this->transport->count());

        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        $this->assertSame(1, $this->transport->count(), 'the office was told once');
        $this->assertTrue($this->alerts->sentToday('rates-stale'));
    }

    public function testDifferentKeysDoNotBlockEachOther(): void
    {
        $this->assertTrue($this->alerts->once('rates-stale', 'One', 'body'));
        $this->assertTrue($this->alerts->once('hold-long', 'Two', 'body'));
        $this->assertTrue($this->alerts->once('db-slow', 'Three', 'body'));

        $this->assertSame(3, $this->transport->count());

        // And claiming a second key does not un-claim the first.
        $this->assertTrue($this->alerts->sentToday('rates-stale'));
        $this->assertTrue($this->alerts->sentToday('hold-long'));
        $this->assertFalse($this->alerts->sentToday('never-sent'));
    }

    public function testTheNextDayTheSameAlertCanGoOutAgain(): void
    {
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        // 23:59 the same UTC day: still the same day, still silent.
        Clock::freeze('2026-09-27 23:59:59 UTC');
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        // One second later it is tomorrow.
        Clock::freeze('2026-09-28 00:00:00 UTC');
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        $this->assertSame(2, $this->transport->count());
    }

    /**
     * An alert whose SEND fails still counts as today's.
     *
     * Retrying a broken mail server every two hours would turn one problem into
     * a hundred messages the moment it came back. The alert is also visible in
     * the admin, so a lost email never hides the underlying problem - which is
     * the trade this makes.
     */
    public function testAFailedSendStillUsesUpTheDaysSlot(): void
    {
        $broken = new NullTransport(null, $this->smtpFailure());
        $alerts = new Alerts($this->notifier($broken));
        $alerts->reset();

        $this->assertFalse($alerts->once('rates-stale', 'Rates are old', 'body'), 'nothing went out');
        $this->assertTrue($alerts->sentToday('rates-stale'), 'but the slot was taken');

        // A working transport later the same day still says nothing.
        $working = new NullTransport();
        $this->assertFalse((new Alerts($this->notifier($working)))->once('rates-stale', 'Rates are old', 'body'));
        $this->assertSame(0, $working->count());
    }

    /**
     * The caller may supply the instant the day is measured from, and it - not
     * the wall clock - decides.
     *
     * Domain\Ops\Cron depends on this. It is handed one instant for the whole
     * run and passes it to every check, so a run that straddles midnight cannot
     * alert as Sunday and record the slot as Monday. It is also how the cron's
     * own tests move the day without freezing the clock under the rest of the
     * suite.
     */
    public function testTheCallersInstantDecidesTheDayNotTheWallClock(): void
    {
        // The frozen wall clock in this test says the 27th; the argument says
        // the 28th, and the argument wins.
        $tomorrow = new \DateTimeImmutable('2026-09-28 06:00:00', new \DateTimeZone('UTC'));

        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body', $tomorrow));
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body', $tomorrow));
        $this->assertTrue($this->alerts->sentToday('rates-stale', $tomorrow));

        // Today's slot was never taken, so today still sends.
        $this->assertFalse($this->alerts->sentToday('rates-stale'), 'the wall-clock day is untouched');
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        $this->assertSame('2026-09-27', $this->alerts->lastSent('rates-stale'), 'the later claim is the stored one');
        $this->assertSame(2, $this->transport->count());
    }

    /**
     * An instant in another zone counts as the UTC day it falls in.
     *
     * 01:00 in Asia/Karachi on the 28th is 20:00 UTC on the 27th, and "once a
     * day" is a UTC day - the cron clock on this host is UTC and cannot be
     * changed. Getting this wrong would send two alerts on every day of the
     * year, five hours apart.
     */
    public function testAnInstantInAnotherZoneIsCountedAsItsUtcDay(): void
    {
        $karachi = new \DateTimeImmutable('2026-09-28 01:00:00', new \DateTimeZone('Asia/Karachi'));

        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body', $karachi));
        $this->assertSame('2026-09-27', $this->alerts->lastSent('rates-stale'), 'that instant is still the 27th in UTC');

        // The frozen wall clock is 08:00 UTC on the 27th, and agrees it is used.
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertSame(1, $this->transport->count());
    }

    /**
     * The row the map lives in is "alerts", a second one is a second set of
     * slots, and only a test may ever name one - so the name is checked against
     * the column it has to fit.
     */
    public function testTheSettingsRowDefaultsToAlertsAndMustFitTheColumn(): void
    {
        $this->assertSame('alerts', Alerts::SETTINGS_KEY);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => new Alerts($this->notifier(new NullTransport()), str_repeat('x', 65)),
        );
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => new Alerts($this->notifier(new NullTransport()), ''),
        );

        // A row of its own keeps its own slots, which is what lets the Ops tests
        // prove a day boundary without writing the live site's alert dates.
        $other = new Alerts($this->notifier($this->transport), 'alerts-test-' . bin2hex(random_bytes(4)));

        try {
            $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
            $this->assertTrue($other->once('rates-stale', 'Rates are old', 'body'), 'a different row, a free slot');
            $this->assertSame(2, $this->transport->count());
        } finally {
            $other->reset();
        }
    }

    public function testSentTodayNeverTakesTheSlotItself(): void
    {
        $this->assertFalse($this->alerts->sentToday('rates-stale'));
        $this->assertFalse($this->alerts->sentToday('rates-stale'));

        // Reading did not claim anything, so the alert still goes out.
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
    }

    public function testResetLetsTheOwnerSendItAgain(): void
    {
        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertFalse($this->alerts->once('rates-stale', 'Rates are old', 'body'));

        $this->alerts->reset();

        $this->assertTrue($this->alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertSame(2, $this->transport->count());
    }

    /* ===================================================================== */
    /*  what goes out                                                        */
    /* ===================================================================== */

    public function testEveryAlertSubjectIsPrefixedAndTagged(): void
    {
        $this->alerts->once('rates-stale', 'Rates were last published 9 days ago', 'body');

        $message = $this->transport->last();

        $this->assertSame('[Speedat] Rates were last published 9 days ago', $message?->subject);
        $this->assertSame('alert', $message?->tag);
        $this->assertSame(self::OFFICE, $message?->to[0] ?? null);
    }

    public function testAnImmediateNoticeIsNeverDeduplicated(): void
    {
        $this->assertTrue($this->alerts->now('A publish failed', 'body'));
        $this->assertTrue($this->alerts->now('A publish failed', 'body'));
        $this->assertTrue($this->alerts->now('A publish failed', 'body'));

        $this->assertSame(3, $this->transport->count(), 'each one deserves a message');
        $this->assertFalse($this->alerts->sentToday('a-publish-failed'));
    }

    public function testAPreparedMessageCarriesItsOwnKey(): void
    {
        $alert = OfficeMessages::ratesStale(9, 41, '2026-09-18', 'https://speedat.test');

        $this->assertTrue($this->alerts->onceMessage($alert));
        $this->assertFalse($this->alerts->onceMessage($alert), 'the key came with the message');
        $this->assertTrue($this->alerts->sentToday('rates-stale'));
        $this->assertSame('[Speedat] Rates were last published 9 days ago', $this->transport->last()?->subject);
    }

    public function testAnAlertKeyFromOutsideThisCodebaseIsRefused(): void
    {
        foreach (['', 'Rates Stale', 'a"b', "a\nb", 'a.b$c', str_repeat('a', 64)] as $bad) {
            $this->assertThrows(
                \InvalidArgumentException::class,
                fn () => $this->alerts->once($bad, 'x', 'y'),
                'accepted the alert key "' . substr($bad, 0, 20) . '"',
            );
        }

        // And the legitimate shapes are all accepted.
        foreach (['rates-stale', 'hold_long', 'ops.v2', 'ns:key', 'a'] as $good) {
            $this->assertTrue($this->alerts->once($good, 'x', 'y'), 'refused "' . $good . '"');
        }
    }

    /**
     * Nothing is sent when no office address is configured, and the gap is
     * recorded rather than swallowed.
     */
    public function testWithNoOfficeAddressNothingGoesOutAndTheGapIsVisible(): void
    {
        $transport = new NullTransport();
        $alerts    = new Alerts(new Notifier($transport, '', self::FROM, 'Speedat', 'speedat.test'));
        $alerts->reset();

        $before = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_skipped'], 0);

        $this->assertFalse($alerts->once('rates-stale', 'Rates are old', 'body'));
        $this->assertSame(0, $transport->count());

        $this->assertSame(
            $before + 1,
            (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_skipped'], 0),
            'a skipped notification must leave an audit row',
        );
    }

    /** A transport that throws never reaches the caller as an exception. */
    public function testAFailingTransportIsRecordedAndReturnsFalse(): void
    {
        $notifier = $this->notifier(new NullTransport(null, $this->smtpFailure()));

        $before = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_failed'], 0);

        $this->assertFalse($notifier->office(new Notification('Subject', 'body')));

        $this->assertSame(
            $before + 1,
            (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['notify_failed'], 0),
        );
    }

    /* ------------------------------------------------------------ helpers */

    private function notifier(NullTransport $transport): Notifier
    {
        return new Notifier($transport, self::OFFICE, self::FROM, 'Speedat International', 'speedat.test');
    }

    /** A transport failure that looks like a real one. */
    private function smtpFailure(): SmtpException
    {
        return SmtpException::connect('smtp.test', 587, 'Connection refused');
    }
}
