<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Audit;
use Speedat\Core\Caps;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Tests\TestCase;

/**
 * The audit log: append-only, and it never throws.
 *
 * "Append-only" is enforced by this application rather than by a grant -
 * Hostinger gives one database user with full rights - so the guarantee is
 * only as good as the claim that no code path writes anything but an INSERT.
 * That claim is checked here against the source of every file that touches the
 * table, not just asserted in a comment. The comment in Audit.php said "one
 * statement in this file and it is an INSERT" while recent() sat below it with
 * a SELECT, which is exactly how a guarantee rots.
 */
final class AuditTest extends TestCase
{
    private string $actor = '';

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->actor = 'audit-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    public function tearDown(): void
    {
        if ($this->actor !== '' && Db::healthy()) {
            Db::exec('DELETE FROM audit_log WHERE actor = ?', [$this->actor]);
        }
    }

    public function testAnEntryIsWrittenAndReadsBackWhole(): void
    {
        $request = Request::synthetic('POST', '/admin/rates/publish', [], [], ['user-agent' => 'TestAgent/1.0']);

        Audit::log($this->actor, 'rates_published', ['version' => 41, 'changes' => 3], $request);

        $row = Db::one('SELECT * FROM audit_log WHERE actor = ? ORDER BY id DESC', [$this->actor]);
        $this->assertNotNull($row);
        $this->assertSame('rates_published', (string) $row['action']);
        $this->assertSame(['version' => 41, 'changes' => 3], Db::unjson($row['detail']));
        $this->assertSame('TestAgent/1.0', (string) $row['user_agent']);
        $this->assertSame(32, strlen((string) $row['ip_hash']), 'the address is hashed, never stored');
        $this->assertStringNotContains('127.0.0.1', (string) $row['ip_hash']);
    }

    public function testWithoutARequestThereIsNoAddressOrAgent(): void
    {
        Audit::log('system', 'cron_run', ['jobs' => 3]);

        $row = Db::one("SELECT * FROM audit_log WHERE action = 'cron_run' ORDER BY id DESC");
        $this->assertNotNull($row);
        $this->assertNull($row['ip_hash']);
        $this->assertNull($row['user_agent']);

        Db::exec('DELETE FROM audit_log WHERE id = ?', [(int) $row['id']]);
    }

    public function testAnEmptyActorBecomesSystemRatherThanNothing(): void
    {
        Audit::log('', 'seed_run', [], null);

        $row = Db::one("SELECT * FROM audit_log WHERE action = 'seed_run' ORDER BY id DESC");
        $this->assertNotNull($row);
        $this->assertSame('system', (string) $row['actor']);

        Db::exec('DELETE FROM audit_log WHERE id = ?', [(int) $row['id']]);
    }

    /**
     * The one case where truncating beats refusing: an over-long actor or
     * action must not lose the audit line, and with STRICT_TRANS_TABLES it
     * would - the INSERT itself would be an error.
     */
    public function testOverLongFieldsAreCutToTheColumnRatherThanLosingTheEntry(): void
    {
        $long = str_repeat('a', 400) . '@example.test';

        Audit::log($long, str_repeat('b', 200), ['ok' => true], Request::synthetic('GET', '/', [], [], [
            'user-agent' => str_repeat('c', 900),
        ]));

        $row = Db::one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame(Caps::of('audit_log.actor'), mb_strlen((string) $row['actor']));
        $this->assertSame(Caps::of('audit_log.action'), mb_strlen((string) $row['action']));
        $this->assertSame(Caps::of('audit_log.user_agent'), mb_strlen((string) $row['user_agent']));

        Db::exec('DELETE FROM audit_log WHERE id = ?', [(int) $row['id']]);
    }

    public function testAHugeDetailBlobIsReplacedByItsShape(): void
    {
        $huge = [];
        for ($i = 0; $i < 400; $i++) {
            $huge['field_' . $i] = str_repeat('x', 200);
        }

        Audit::log($this->actor, 'big_detail', $huge);

        $row = Db::one('SELECT detail FROM audit_log WHERE actor = ? ORDER BY id DESC', [$this->actor]);
        $this->assertNotNull($row);

        $detail = Db::unjson($row['detail']);
        $this->assertTrue($detail['truncated'] ?? false, 'the blob is replaced, not stored');
        $this->assertCount(40, $detail['keys'] ?? [], 'the first forty keys are kept, so the entry still means something');
    }

    /**
     * A failed audit write is logged and swallowed. The action it describes
     * has already happened: throwing here would roll back a price publish
     * because the diary was full, and would hide the event as well.
     */
    /**
     * A detail that cannot be JSON keeps the entry, without its detail.
     *
     * clamp() encodes the detail before the insert, and a resource makes that
     * fail - which drops it into the same "too big to store" branch as a huge
     * blob. That is the right outcome: the event is what matters and the detail
     * is the commentary.
     */
    public function testADetailThatCannotBeStoredStillLeavesTheEntry(): void
    {
        $handle = fopen('php://memory', 'rb');

        Audit::log($this->actor, 'unserialisable', ['handle' => $handle]);

        if (is_resource($handle)) {
            fclose($handle);
        }

        $row = Db::one('SELECT detail FROM audit_log WHERE actor = ? AND action = ?', [$this->actor, 'unserialisable']);
        $this->assertNotNull($row, 'the line is written even when its detail is not storable');
        $this->assertTrue(Db::unjson($row['detail'])['truncated'] ?? false);
    }

    /**
     * And a database that cannot be written at all is swallowed.
     *
     * The action being audited has already happened: throwing here would roll
     * back a price publish because the diary was full, and would hide the
     * event as well as failing to record it.
     */
    public function testAFailedWriteNeverThrows(): void
    {
        $database = \Speedat\Core\Config::str('db.name');

        try {
            \Speedat\Core\Config::set('db.name', 'speedat_no_such_database');
            Db::reset();

            // No assertThrows: the point is that nothing comes out of here.
            Audit::log($this->actor, 'while_the_database_is_down', ['version' => 1]);
            $this->assertTrue(true, 'Audit::log() returned rather than throwing');
        } finally {
            \Speedat\Core\Config::set('db.name', $database);
            Db::reset();
        }

        $this->assertTrue(Db::healthy(), 'and the connection recovers afterwards');
    }

    public function testRecentIsNewestFirstAndItsLimitsAreClamped(): void
    {
        foreach (['first', 'second', 'third'] as $action) {
            Audit::log($this->actor, 'recent_' . $action, []);
        }

        $rows = Audit::recent(500);
        $mine = array_values(array_filter(
            $rows,
            fn (array $row): bool => (string) $row['actor'] === $this->actor
        ));

        $this->assertCount(3, $mine);
        $this->assertSame('recent_third', (string) $mine[0]['action'], 'newest first');

        // The limit is clamped at both ends rather than trusted.
        $this->assertCount(1, Audit::recent(0), 'a zero limit is raised to one');
        $this->assertTrue(count(Audit::recent(10_000)) <= 500, 'and a huge one is capped at 500');
        $this->assertCount(1, Audit::recent(1, -5), 'a negative offset is zero');
    }

    /**
     * THE APPEND-ONLY CLAIM, checked against the source.
     *
     * Every file that names audit_log is read and every SQL verb applied to it
     * is collected. INSERT and SELECT are the two that may appear. A DELETE in
     * a test's own tear-down is not part of the application, so only app/ and
     * bin/ are read - the tests above delete their own rows, and a suite that
     * could not would leave the table growing forever.
     */
    public function testNothingInTheApplicationRewritesTheTable(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (!str_contains($source, 'audit_log')) {
                continue;
            }

            // Every verb that appears anywhere in the file's SQL-looking
            // strings. Deliberately crude: it over-reports rather than
            // under-reports, and over-reporting is a conversation while
            // under-reporting is a silent hole.
            foreach (['UPDATE audit_log', 'DELETE FROM audit_log', 'TRUNCATE audit_log', 'DROP TABLE audit_log'] as $verb) {
                if (stripos($source, $verb) !== false) {
                    $offenders[] = basename($file) . ': ' . $verb;
                }
            }
        }

        $this->assertSame([], $offenders, 'audit_log is append-only: ' . implode(', ', $offenders));
    }

    /** And the docblock's own claim about this file, which used to be wrong. */
    public function testAuditsOwnStatementsAreOneInsertAndOneSelect(): void
    {
        $source = (string) file_get_contents(Paths::app() . '/Core/Audit.php');

        // Only the code, so the docblock cannot satisfy its own claim.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        $this->assertSame(1, substr_count($code, "Db::insert('audit_log'"), 'one INSERT');
        $this->assertSame(1, preg_match_all('/\bSELECT\b/i', $code), 'one SELECT, in recent()');
        $this->assertSame(0, preg_match_all('/\bUPDATE\b|\bDELETE\b/i', $code), 'and nothing else');
    }

    /** @return list<string> every .php under app/ and bin/ */
    private function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Paths::root(), \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = str_replace('\\', '/', $entry->getPathname());

            if (!$entry->isFile() || !str_ends_with($path, '.php')) {
                continue;
            }
            // Judged on the path BELOW the project root: a root that is itself
            // called /app (the Docker image) would otherwise put every file,
            // tests/ included, "under app/".
            $relative = substr($path, strlen(rtrim(str_replace('\\', '/', Paths::root()), '/')) + 1);
            if (!str_starts_with($relative, 'app/') && !str_starts_with($relative, 'bin/')) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }
}
