<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Ops\Counts;
use Speedat\Domain\Ops\Cron;
use Speedat\Domain\Ops\DailyOnce;
use Speedat\Domain\Ops\Housekeeping;
use Speedat\Tests\Ops\Support\FakeHold;
use Speedat\Tests\Ops\Support\FakeVersions;
use Speedat\Tests\Ops\Support\RecordingOffice;
use Speedat\Tests\Ops\Support\ThrowingVersions;

/**
 * The periodic job end to end: the token, the two checks, the once-a-day
 * alerts, the housekeeping, and what a failure does to the exit code.
 *
 * The cache directory is redirected into a scratch directory the test owns, so
 * a run cannot sweep the real one.
 *
 * THE ALERTS ARE THE REAL ONES. Domain\Notify\Alerts, Domain\Notify\Notifier and
 * Vendor\Smtp\Message all run; only the socket is replaced, by RecordingOffice.
 * There is no fake alerts class to inject any more because there is no second
 * alerts implementation - Ops\Alerts and its Contract\Notifier are gone, and the
 * cron sends through the one path everything else sends through.
 */
final class CronTest extends OpsTestCase
{
    private const TOKEN = 'ops-test-cron-token-9f4c2b7e';

    private string $savedCronToken = '';

    private string $savedCachePath = '';

    private string $cacheDir = '';

    private string $savedDbHost = '';

    private int $savedDbPort = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->savedCronToken = Config::str('tokens.cron');
        $this->savedCachePath = Config::str('paths.cache');
        $this->savedDbHost    = Config::str('db.host');
        $this->savedDbPort    = Config::int('db.port', 3306);
        $this->cacheDir       = Paths::tmp() . '/' . $this->tag . '-cron-cache';

        Config::set('tokens.cron', self::TOKEN);
        Paths::ensureWritable($this->cacheDir);
        Config::set('paths.cache', $this->cacheDir);
    }

    public function tearDown(): void
    {
        Config::set('tokens.cron', $this->savedCronToken);
        Config::set('paths.cache', $this->savedCachePath);

        // Put the connection back BEFORE the parent cleans its rows up: the
        // outage test leaves it pointed at a closed port, and a tear-down that
        // cannot reach the database silently deletes nothing.
        Config::set('db.host', $this->savedDbHost);
        Config::set('db.port', $this->savedDbPort);
        Db::reset();

        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            @rmdir($this->cacheDir);
        }

        parent::tearDown();
    }

    /**
     * A cron wired to fakes.
     *
     * The alerts and the housekeeping keep their markers in two app_settings
     * rows, exactly as production does (`alerts` and `ops`) - here both are
     * scoped to this test's tag. The names are stable within a test, so a second
     * cron() built in the same test remembers what the first one sent, which is
     * how the "once a day, and again tomorrow" assertions work.
     */
    private function cron(
        \DateTimeImmutable $now,
        RecordingOffice $office,
        string $settingsKey,
        ?FakeVersions $versions = null,
        ?FakeHold $hold = null,
    ): Cron {
        return new Cron(
            $office->alerts($this->settingsKey('alerts')),
            new Counts($now),
            $versions ?? FakeVersions::publishedAt('2026-09-26 06:00:00', 12),
            $hold ?? FakeHold::off(),
            new Housekeeping(new DailyOnce($settingsKey), $now),
            $now,
        );
    }

    public function testWithoutTheSecretItIs401AndRunsNothing(): void
    {
        $office = new RecordingOffice();
        $cron   = $this->cron(self::utc('now'), $office, $this->settingsKey('ops'));

        $response = $cron->respond(null);

        $this->assertSame(401, $response->status);
        $this->assertSame(
            '{"error":{"code":"unauthorized","message":"Bad or missing cron secret"}}',
            $response->body,
            'the wording the TypeScript answered with'
        );
        $this->assertSame(0, $office->count(), 'a refused call does not alert anybody');
        $this->assertSame(401, $cron->respond('Bearer wrong')->status);
        $this->assertSame(401, $cron->respond(self::TOKEN)->status, 'the scheme word counts');
        $this->assertSame(0, $office->count());
    }

    public function testWithTheSecretItRunsAndReportsEveryJob(): void
    {
        $office   = new RecordingOffice();
        $response = $this->cron(self::utc('now'), $office, $this->settingsKey('ops'))
            ->respond('Bearer ' . self::TOKEN);

        $this->assertSame(200, $response->status);

        $body = $response->decoded();
        $this->assertTrue($body['ok'] ?? false);

        $jobs = is_array($body['jobs'] ?? null) ? $body['jobs'] : [];
        foreach (['rates', 'hold', 'counts', 'sessions', 'rate_limits', 'cache', 'environment'] as $job) {
            $this->assertTrue(array_key_exists($job, $jobs), 'the "' . $job . '" job reported');
        }

        $this->assertSame([], $body['failures'] ?? null);
    }

    public function testFreshRatesAndNoHoldAlertNobody(): void
    {
        $now    = self::utc('2026-09-27 06:00:00');
        $office = new RecordingOffice();

        $result = $this->cron(
            $now,
            $office,
            $this->settingsKey('ops'),
            FakeVersions::publishedAt('2026-09-26 06:00:00', 12),
            FakeHold::off(),
        )->run();

        $this->assertTrue($result->ok());
        $this->assertSame(0, $office->count(), 'a day-old publish is not stale');
        $this->assertSame(1, $result->checks['ratesAgeDays'] ?? null);
        // The key is present and null - "no hold" is reported, not omitted.
        $this->assertTrue(array_key_exists('holdHours', $result->checks), 'holdHours is reported');
        $this->assertNull($result->checks['holdHours'], 'no hold means no hours');
    }

    public function testStaleRatesAlertOncePerDay(): void
    {
        $key      = $this->settingsKey('ops');
        $office   = new RecordingOffice();
        $monday   = self::utc('2026-09-27 06:00:00');
        // Published nine days before: past the seven-day default.
        $versions = FakeVersions::publishedAt('2026-09-18 06:00:00', 12);

        $first = $this->cron($monday, $office, $key, $versions)->run();

        $this->assertSame(9, $first->checks['ratesAgeDays'] ?? null);
        $this->assertTrue($first->checks['staleAlert'] ?? false, 'the office was told');
        $this->assertSame(1, $office->count());
        $this->assertStringContains('Rates were last published 9 days ago', $office->lastSubject());
        $this->assertStringContains('Version 12 has been live since 2026-09-18', $office->lastText());

        // The same day, two hours later - the hourly cron runs again.
        $second = $this->cron($monday->modify('+2 hours'), $office, $key, $versions)->run();

        $this->assertFalse($second->checks['staleAlert'] ?? true, 'and not told twice');
        $this->assertSame(1, $office->count(), 'exactly one message today');

        // Tomorrow it is worse, and worth saying again.
        $third = $this->cron(self::utc('2026-09-28 06:00:00'), $office, $key, $versions)->run();

        $this->assertTrue($third->checks['staleAlert'] ?? false);
        $this->assertSame(2, $office->count());
        $this->assertStringContains('10 days ago', $office->lastSubject());
    }

    public function testALongHoldAlertsOncePerDay(): void
    {
        $key    = $this->settingsKey('ops');
        $office = new RecordingOffice();
        $now    = self::utc('2026-09-27 18:00:00');
        // On hold since 04:00 UTC: fourteen hours, past the twelve-hour default.
        $hold = FakeHold::onSince('2026-09-27 04:00:00', 'owner@speedat.test');

        $first = $this->cron($now, $office, $key, null, $hold)->run();

        $this->assertSame(14, $first->checks['holdHours'] ?? null);
        $this->assertTrue($first->checks['holdAlert'] ?? false);
        $this->assertSame(1, $office->count());
        $this->assertStringContains('Prices have been off the website for 14 hours', $office->lastSubject());
        $this->assertStringContains('owner@speedat.test put prices on hold at', $office->lastText());
        $this->assertStringContains('press Resume', $office->lastText());

        $second = $this->cron($now->modify('+2 hours'), $office, $key, null, $hold)->run();

        $this->assertFalse($second->checks['holdAlert'] ?? true);
        $this->assertSame(1, $office->count(), 'exactly one message today');

        $next = $this->cron(self::utc('2026-09-28 06:00:00'), $office, $key, null, $hold)->run();

        $this->assertTrue($next->checks['holdAlert'] ?? false, 'still held tomorrow, said again');
        $this->assertSame(2, $office->count());
    }

    public function testAShortHoldIsAnAdminAtWork(): void
    {
        $office = new RecordingOffice();

        $result = $this->cron(
            self::utc('2026-09-27 12:00:00'),
            $office,
            $this->settingsKey('ops'),
            null,
            FakeHold::onSince('2026-09-27 09:00:00'),
        )->run();

        $this->assertSame(3, $result->checks['holdHours'] ?? null);
        $this->assertSame(0, $office->count(), 'three hours is somebody editing, not an outage');
    }

    public function testTheTwoAlertsDoNotShareTheirSlot(): void
    {
        $office = new RecordingOffice();

        $result = $this->cron(
            self::utc('2026-09-27 18:00:00'),
            $office,
            $this->settingsKey('ops'),
            FakeVersions::publishedAt('2026-09-18 06:00:00', 12),
            FakeHold::onSince('2026-09-27 04:00:00'),
        )->run();

        $this->assertTrue($result->checks['staleAlert'] ?? false);
        $this->assertTrue($result->checks['holdAlert'] ?? false);
        $this->assertSame(2, $office->count(), 'two different problems, two messages');
    }

    public function testNothingPublishedIsNotAStaleAlert(): void
    {
        $office = new RecordingOffice();

        $result = $this->cron(self::utc('now'), $office, $this->settingsKey('ops'), FakeVersions::none())->run();

        $this->assertTrue($result->ok());
        $this->assertTrue(array_key_exists('ratesAgeDays', $result->checks), 'ratesAgeDays is reported');
        $this->assertNull($result->checks['ratesAgeDays'], 'an empty database has no age');
        $this->assertSame(0, $office->count(), 'a site that has never published is a deploy problem, not a stale one');
    }

    public function testAFailingJobIsRecordedAndTheRestStillRun(): void
    {
        $office = new RecordingOffice();
        $now    = self::utc('now');

        $cron = new Cron(
            $office->alerts($this->settingsKey('alerts')),
            new Counts($now),
            new ThrowingVersions('the version table is unavailable'),
            FakeHold::off(),
            new Housekeeping(new DailyOnce($this->settingsKey('ops')), $now),
            $now,
        );

        $result = $cron->run();

        $this->assertFalse($result->ok(), 'a job that threw makes the run a failure');
        $this->assertSame(1, $result->exitCode(), 'so the cron log shows it');
        $this->assertSame(500, $result->httpStatus());
        $this->assertSame(1, count($result->failures));
        $this->assertStringContains('rates:', $result->failures[0] ?? '');

        // Everything after the failure still happened.
        foreach (['hold', 'counts', 'sessions', 'rate_limits', 'cache', 'environment'] as $job) {
            $this->assertTrue(array_key_exists($job, $result->jobs), 'the "' . $job . '" job still ran');
        }

        $this->assertSame(500, $cron->respond('Bearer ' . self::TOKEN)->status);
    }

    /**
     * The cron reports the numbers through Counts, so its log and the admin
     * badges cannot tell the owner two different things - the bug this helper
     * exists to prevent.
     */
    public function testTheCronReportsTheSameNumbersTheBadgesShow(): void
    {
        $now    = self::utc('now');
        $office = new RecordingOffice();

        $this->insertQuote($now->sub(new \DateInterval('PT1H')));
        $this->insertQuote($now->sub(new \DateInterval('PT2H')), true);
        $this->insertLead('new');
        $this->insertShipment('exception');

        $result = $this->cron($now, $office, $this->settingsKey('ops'))->run();

        // What the badge would read, asked separately after the run.
        $badge = (new Counts($now))->all();

        foreach ($badge as $name => $number) {
            $this->assertSame($number, $result->checks[$name] ?? null, $name . ' agrees with the badge');
        }

        $this->assertTrue(($result->checks['leadsNew'] ?? 0) >= 1, 'the lead just added is waiting');
        $this->assertTrue(($result->checks['shipmentsException'] ?? 0) >= 1, 'and the exception is reported');
        $this->assertStringContains('lead(s) waiting', $result->jobs['counts'] ?? '');
    }

    /**
     * A cron run against an unreachable database must come back non-zero.
     *
     * It is the case worth a test of its own: the two alert checks are written
     * to survive a database that is down (a page asking "are prices held" must
     * not become an error page), so without the counting job a full outage
     * would have exited 0 and the cron log would have said nothing was wrong.
     */
    public function testADatabaseThatIsDownMakesTheRunFail(): void
    {
        $office = new RecordingOffice();
        $cron   = $this->cron(self::utc('now'), $office, $this->settingsKey('ops'));

        // Port 1 on loopback: refused instantly, so this is a real connection
        // failure and not a timeout. tearDown puts the connection back.
        Config::set('db.host', '127.0.0.1');
        Config::set('db.port', 1);
        Db::reset();

        $result = $cron->run();

        $this->assertFalse($result->ok(), 'a run that could not read the database is not a successful run');
        $this->assertSame(1, $result->exitCode(), 'so the cron log shows it');
        $this->assertTrue(
            in_array(true, array_map(
                static fn (string $failure): bool => str_starts_with($failure, 'counts:'),
                $result->failures
            ), true),
            'the counting job is the one that reports it'
        );
        $this->assertStringNotContains('127.0.0.1', $result->text(), 'and the cron log gets no host name');
    }

    public function testNothingAboutRateSheetsIsCheckedOrSaid(): void
    {
        $office = new RecordingOffice();

        $result = $this->cron(
            self::utc('2026-09-27 18:00:00'),
            $office,
            $this->settingsKey('ops'),
            FakeVersions::publishedAt('2026-09-18 06:00:00', 12),
            FakeHold::onSince('2026-09-27 04:00:00'),
        )->run();

        $body = json_encode($result->body()) . json_encode($office->sent());
        $this->assertNotSame(false, $body);

        foreach (['sheet', 'Sheet', 'import', 'Import', 'Excel', 'mapping', 'intake'] as $word) {
            $this->assertStringNotContains($word, (string) $body, '"' . $word . '" belongs to a feature that was removed');
        }
    }

    public function testTheCronLogTextNamesEveryJob(): void
    {
        $office = new RecordingOffice();
        $text   = $this->cron(self::utc('now'), $office, $this->settingsKey('ops'))->run()->text();

        foreach (['rates', 'hold', 'counts', 'sessions', 'rate_limits', 'cache', 'environment'] as $job) {
            $this->assertStringContains($job, $text);
        }

        $this->assertStringContains('0 failure(s).', $text);
    }
}
