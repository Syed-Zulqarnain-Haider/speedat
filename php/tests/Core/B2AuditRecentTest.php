<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Audit;
use Speedat\Core\Db;
use Speedat\Tests\TestCase;

/**
 * Audit::recent() can leave named actions out.
 *
 * The Security page lists the last forty rows. The hourly cron writes a
 * cron_run row every run, so unfiltered that list was under two days of cron
 * and yesterday's sign-in had scrolled off it (2026-10-25). The rows are still
 * written; the panel just does not show them.
 *
 * Nothing here deletes from audit_log: it is append-only, in the test database
 * as much as anywhere, so assertions look only at this test's own actor.
 */
final class B2AuditRecentTest extends TestCase
{
    private string $actor = '';

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable');
        }

        $this->actor = 'b2audit' . bin2hex(random_bytes(4)) . '@speedat.test';
    }

    public function testExcludedActionsAreLeftOutAndTheRestKeepTheirOrder(): void
    {
        Audit::log($this->actor, 'login', []);
        Audit::log($this->actor, 'cron_run', []);
        Audit::log($this->actor, 'totp_disabled', []);
        Audit::log($this->actor, 'rates_stale', []);

        $mine = static fn (array $rows, string $actor): array => array_values(array_map(
            static fn (array $row): string => (string) $row['action'],
            array_filter($rows, static fn (array $row): bool => ($row['actor'] ?? '') === $actor)
        ));

        $all = $mine(Audit::recent(50), $this->actor);
        $this->assertSame(['rates_stale', 'totp_disabled', 'cron_run', 'login'], $all, 'unfiltered, everything');

        $people = $mine(Audit::recent(50, 0, ['cron_run', 'rates_stale', 'hold_long']), $this->actor);
        $this->assertSame(['totp_disabled', 'login'], $people, 'the machine rows are gone, newest first still');
    }

    public function testAnEmptyExclusionListIsTheOldQuery(): void
    {
        Audit::log($this->actor, 'cron_run', []);

        $rows = Audit::recent(5, 0, ['', '']);
        $this->assertTrue(count($rows) >= 1, 'blank names exclude nothing');
        $this->assertCount(1, Audit::recent(1, 0, []), 'and the limit still applies');
    }
}
