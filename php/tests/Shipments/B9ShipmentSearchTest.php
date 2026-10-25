<?php

declare(strict_types=1);

namespace Speedat\Tests\Shipments;

use Speedat\Core\Db;
use Speedat\Domain\Shipments\Shipment;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Tests\TestCase;

/**
 * The desk search as the office actually types it: a number read down the
 * phone with a space where the dash goes, and a customer plus where the
 * parcel went. Both used to work through the PHP filter's nameKey, and both
 * stopped when the search moved into SQL as one raw substring - silently, with
 * "No shipment matches" over a parcel that was in the table.
 */
final class B9ShipmentSearchTest extends TestCase
{
    private const ACTOR = 'owner@speedat.test';

    /** Unlikely to be anybody else's customer in a shared test database. */
    private const CUSTOMER = 'Qudsia Bninesearch';

    private ShipmentRepository $shipments;

    /** @var list<string> */
    private array $created = [];

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->shipments = new ShipmentRepository();
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->created as $id) {
            Db::exec('DELETE FROM shipment_events WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
        }

        $this->created = [];
    }

    public function testANumberReadDownThePhoneFindsTheShipmentInAnySpelling(): void
    {
        $shipment = $this->create('ca');
        $bare     = substr($shipment->id, 3);

        foreach ([
            $shipment->id,
            strtolower($shipment->id),
            'SH ' . $bare,
            'sh ' . strtolower($bare),
            'SH' . $bare,
            substr($bare, 0, 4) . ' ' . substr($bare, 4),
            $bare,
        ] as $typed) {
            $this->assertTrue(
                in_array($shipment->id, $this->ids($typed), true),
                '"' . $typed . '" should find ' . $shipment->id,
            );
        }
    }

    public function testACustomerAndADestinationTogetherFindOnlyThatShipment(): void
    {
        $canada = $this->create('ca');
        $uk     = $this->create('gb');

        // What the controller resolves from the live card, per word.
        $named = ['canada' => ['ca']];

        $found = $this->ids('qudsia canada', $named);
        $this->assertTrue(in_array($canada->id, $found, true), 'customer plus destination finds the Canada parcel');
        $this->assertFalse(in_array($uk->id, $found, true), 'every word must match - the UK parcel is not "canada"');

        $this->assertTrue(in_array($canada->id, $this->ids('Canada, Qudsia-Bninesearch', $named), true), 'the order and the punctuation do not matter');

        $both = $this->ids('bninesearch qudsia');
        $this->assertTrue(in_array($canada->id, $both, true) && in_array($uk->id, $both, true), 'two words of one name find both');
    }

    public function testAWordThatMatchesNothingStillEmptiesTheList(): void
    {
        $this->create('ca');

        $this->assertSame([], $this->ids('qudsia zzqqxxnotaword'));
    }

    /**
     * @param array<string,list<string>> $named
     * @return list<string>
     */
    private function ids(string $search, array $named = []): array
    {
        return array_map(
            static fn (Shipment $s): string => $s->id,
            $this->shipments->list(ShipmentRepository::ANY, 500, $search, $named),
        );
    }

    private function create(string $destId): Shipment
    {
        $shipment = $this->shipments->create(
            null,
            null,
            self::CUSTOMER,
            '03001234567',
            'Imran Khan',
            $destId,
            'express',
            '',
            null,
            '',
            self::ACTOR,
        );
        $this->created[] = $shipment->id;

        return $shipment;
    }
}
