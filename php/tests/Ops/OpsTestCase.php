<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Db;
use Speedat\Tests\TestCase;

/**
 * The shared half of the Ops tests: a real database, rows that are tagged so
 * they can be found again, and a tear-down that removes every one of them.
 *
 * Two rules these tests follow, both learned the hard way:
 *
 *  - ASSERT DELTAS, NOT TOTALS. The test database is shared with every other
 *    suite in the repository and is not truncated between runs. A test that
 *    expects `leads_new == 3` passes alone and fails the moment somebody else
 *    adds a fixture; one that expects "three more than when I started" is
 *    true whatever else is in the table.
 *  - NEVER DELETE FROM audit_log. It is append-only by design, in the test
 *    database as much as anywhere else, so the rows this suite writes there
 *    stay - which is exactly why the notify-failure count is measured as a
 *    delta too.
 */
abstract class OpsTestCase extends TestCase
{
    /** Unique per test, so a run interrupted half-way cannot poison the next one. */
    protected string $tag = '';

    /** @var list<string> */
    private array $quoteIds = [];

    /** @var list<string> */
    private array $shipmentIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    /** @var list<string> */
    private array $settingsKeys = [];

    public function setUp(): void
    {
        $this->tag = 'optest' . bin2hex(random_bytes(5));

        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->quoteIds as $id) {
            Db::exec('DELETE FROM quotes WHERE id = ?', [$id]);
        }
        foreach ($this->shipmentIds as $id) {
            Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            Db::exec('DELETE FROM sessions WHERE id = ?', [$id]);
        }
        foreach ($this->settingsKeys as $key) {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [$key]);
        }

        Db::exec('DELETE FROM leads WHERE email = ?', [$this->tag . '@example.invalid']);
        Db::exec('DELETE FROM rate_limits WHERE k LIKE ?', [$this->tag . ':%']);

        $this->quoteIds     = [];
        $this->shipmentIds  = [];
        $this->sessionIds   = [];
        $this->settingsKeys = [];
    }

    protected static function utc(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    protected static function sql(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** An app_settings key this test owns; it is deleted in tearDown. */
    protected function settingsKey(string $suffix): string
    {
        $key                  = substr($this->tag . '-' . $suffix, 0, 64);
        $this->settingsKeys[] = $key;

        return $key;
    }

    protected function insertQuote(\DateTimeImmutable $createdAt, bool $booked = false): string
    {
        $id = substr($this->tag . bin2hex(random_bytes(4)), 0, 32);

        Db::insert('quotes', [
            'id'         => $id,
            'created_at' => self::sql($createdAt),
            'version'    => 1,
            'dest_id'    => 'gb',
            'service_id' => 'express',
            'type'       => 'pkg',
            'billable_g' => 1000,
            'total'      => 4500,
            'detail'     => '{}',
            'booked'     => $booked ? 1 : 0,
        ]);

        $this->quoteIds[] = $id;

        return $id;
    }

    protected function insertLead(string $status = 'new'): void
    {
        Db::insert('leads', [
            'kind'    => 'message',
            'name'    => 'Ops test',
            'phone'   => '',
            'email'   => $this->tag . '@example.invalid',
            'message' => '',
            'notes'   => '',
            'status'  => $status,
        ]);
    }

    protected function insertShipment(string $status): string
    {
        $id = substr($this->tag . bin2hex(random_bytes(4)), 0, 32);

        Db::insert('shipments', [
            'id'         => $id,
            'dest_id'    => 'gb',
            'service_id' => 'express',
            'status'     => $status,
            'notes'      => '',
            'created_by' => 'ops-test',
        ]);

        $this->shipmentIds[] = $id;

        return $id;
    }

    protected function insertSession(\DateTimeImmutable $expiresAt, \DateTimeImmutable $lastSeen): string
    {
        $id = hash('sha256', $this->tag . bin2hex(random_bytes(8)));

        Db::insert('sessions', [
            'id'         => $id,
            'email'      => $this->tag . '@example.invalid',
            'created_at' => self::sql($lastSeen),
            'last_seen'  => self::sql($lastSeen),
            'user_agent' => 'ops-test',
            'expires_at' => self::sql($expiresAt),
        ]);

        $this->sessionIds[] = $id;

        return $id;
    }

    protected function insertRateLimitWindow(\DateTimeImmutable $windowStart): string
    {
        $key = $this->tag . ':' . bin2hex(random_bytes(4));

        Db::insert('rate_limits', [
            'k'            => $key,
            'window_start' => self::sql($windowStart),
            'count'        => 1,
        ]);

        return $key;
    }
}
