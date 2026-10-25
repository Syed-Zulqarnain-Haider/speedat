<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Db;
use Speedat\Domain\Ops\DailyOnce;

/**
 * The once-a-day marker Housekeeping keeps in app_settings.
 *
 * These assertions used to live in tests/Ops/AlertsTest.php, alongside a second
 * port of alerts.ts that has been deleted (Domain\Notify\Alerts is the only one
 * now, and it claims its own day - see tests/Notify/AlertsTest.php). The marker
 * itself survived the consolidation because Housekeeping still needs it for the
 * environment line, so its own tests move here rather than going with the file.
 *
 * Every test uses its own app_settings key, so a run cannot disturb the real
 * `ops` row and two runs cannot disturb each other.
 */
final class DailyOnceTest extends OpsTestCase
{
    public function testTheFirstCallOfTheDayWinsAndTheRestDoNot(): void
    {
        $once   = new DailyOnce($this->settingsKey('ops'));
        $monday = self::utc('2026-09-27 06:00:00');

        $this->assertTrue($once->claim('environment', $monday), 'the first call of the day claims it');
        $this->assertFalse($once->claim('environment', $monday->modify('+2 hours')), 'later calls today do not');
        $this->assertFalse($once->claim('environment', self::utc('2026-09-27 23:59:59')), '23:59 is still today');
        $this->assertTrue($once->claim('environment', self::utc('2026-09-28 00:00:00')), 'a new UTC day is a new slot');
    }

    public function testDifferentMarkersDoNotShareASlot(): void
    {
        $once = new DailyOnce($this->settingsKey('ops'));
        $at   = self::utc('2026-09-27 06:00:00');

        $this->assertTrue($once->claim('environment', $at));
        $this->assertTrue($once->claim('something-else', $at), 'a different job is a different marker');
        $this->assertFalse($once->claim('environment', $at));
    }

    public function testTheSlotIsInTheDatabaseAndNotInTheObject(): void
    {
        $key  = $this->settingsKey('ops');
        $once = new DailyOnce($key);
        $at   = self::utc('2026-09-27 06:00:00');

        $this->assertFalse($once->doneToday('environment', $at), 'nothing claimed yet');

        $this->assertTrue($once->claim('environment', $at));

        $this->assertTrue($once->doneToday('environment', $at));
        $this->assertSame('2026-09-27', $once->lastClaimed('environment'));

        // A second instance reading the same row agrees: the state is shared,
        // which is the whole reason it is in the database and not in memory.
        $this->assertFalse((new DailyOnce($key))->claim('environment', $at), 'another runner gets nothing');

        $stored = Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ?', [$key]));
        $this->assertSame('2026-09-27', $stored['environment'] ?? null);
    }

    public function testReadingNeverClaims(): void
    {
        $once = new DailyOnce($this->settingsKey('ops'));
        $at   = self::utc('2026-09-27 06:00:00');

        $this->assertFalse($once->doneToday('environment', $at));
        $this->assertFalse($once->doneToday('environment', $at));
        $this->assertNull($once->lastClaimed('environment'));

        // Two reads later the slot is still free.
        $this->assertTrue($once->claim('environment', $at));
    }

    public function testASettingsKeyThatCannotFitTheColumnIsRefused(): void
    {
        // app_settings.k is VARCHAR(64). A key that does not fit would be
        // truncated into somebody else's row, so it is refused outright.
        $this->assertThrows(\InvalidArgumentException::class, static fn () => new DailyOnce(str_repeat('x', 65)));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => new DailyOnce(''));

        // The boundary is inclusive: exactly 64 characters fits the column, so
        // the bug-catcher must not fire on it. doneToday() only reads, so this
        // leaves no row behind.
        $longest = str_repeat('x', 64);
        $this->assertFalse((new DailyOnce($longest))->doneToday('environment'), 'a 64-character row name is accepted');
    }
}
