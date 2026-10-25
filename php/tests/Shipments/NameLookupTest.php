<?php

declare(strict_types=1);

namespace Speedat\Tests\Shipments;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\Tests\TestCase;

/**
 * The bug the Next.js admin had: a shipment to a country the owner later
 * removed from the rates printed the raw id.
 *
 * The setup is the real one. Two published versions: an older one that carried
 * the United Kingdom, and a newer one that does not because the airline stopped
 * flying there. A shipment booked under the older version still has `dest_id`
 * = "gb", and the customer must still read "United Kingdom".
 *
 * The version numbers are at the top of the INT UNSIGNED range so they sort
 * above anything else in the table and cannot be confused with real data; both
 * rows are removed afterwards.
 */
final class NameLookupTest extends TestCase
{
    private int $oldVersion = 0;

    private int $newVersion = 0;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $base             = 4_000_000_000 + random_int(0, 100_000) * 10;
        $this->oldVersion = $base;
        $this->newVersion = $base + 1;

        // The older document: three countries, two services.
        $this->publish($this->oldVersion, [
            ['id' => 'ca', 'name' => 'Canada'],
            ['id' => 'gb', 'name' => 'United Kingdom'],
            ['id' => 'ae', 'name' => 'United Arab Emirates'],
        ]);

        // The newer one: the United Kingdom is gone.
        $this->publish($this->newVersion, [
            ['id' => 'ca', 'name' => 'Canada'],
            ['id' => 'ae', 'name' => 'United Arab Emirates'],
        ]);
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        Db::exec('DELETE FROM versions WHERE version IN (?, ?)', [$this->oldVersion, $this->newVersion]);
    }

    public function testALiveDestinationIsNamedFromTheLiveDocument(): void
    {
        $names = new NameLookup();

        $this->assertSame('Canada', $names->destinationName('ca'));
        $this->assertSame('United Arab Emirates', $names->destinationName('ae'));
    }

    /** THE BUG. A retired destination still prints its name, not its id. */
    public function testARetiredDestinationStillPrintsItsName(): void
    {
        $names = new NameLookup();

        $this->assertSame(
            'United Kingdom',
            $names->destinationName('gb'),
            'a country removed from the rates must not print as "gb" to the customer',
        );
    }

    public function testTheBatchLookupAnswersLiveAndRetiredIdsTogether(): void
    {
        $names = new NameLookup();

        $found = $names->destinations(['ca', 'gb', 'ae', 'zz', '']);

        $this->assertSame('Canada', $found['ca'] ?? null);
        $this->assertSame('United Kingdom', $found['gb'] ?? null);
        $this->assertSame('United Arab Emirates', $found['ae'] ?? null);
        $this->assertFalse(array_key_exists('zz', $found), 'an id no version ever knew is left out');
        $this->assertFalse(array_key_exists('', $found));
    }

    public function testAnIdNoVersionEverKnewFallsBackToItself(): void
    {
        $names = new NameLookup();

        // Better a visible "zz" than an empty cell: a record written by hand or
        // by something broken should look wrong rather than look blank.
        $this->assertSame('zz', $names->destinationName('zz'));
        $this->assertSame('', $names->destinationName(''));
    }

    public function testServicesAreLookedUpTheSameWay(): void
    {
        $names = new NameLookup();

        $this->assertSame('Express', $names->serviceName('express'));
        $this->assertSame('Sea freight', $names->serviceName('sea'), 'a retired service keeps its name too');
        $this->assertSame('nope', $names->serviceName('nope'));
    }

    /**
     * An id containing a LIKE wildcard does not match a different destination.
     *
     * The retired-name search is a JSON_SEARCH, whose needle is a LIKE pattern.
     * Without escaping, an id of "%" would match the first destination in the
     * newest document and confidently report the wrong country.
     */
    public function testAWildcardInAnIdDoesNotMatchSomethingElse(): void
    {
        $names = new NameLookup();

        foreach (['%', '_', 'c%', '_a', '\\', 'c_'] as $mischief) {
            $this->assertSame(
                $mischief,
                $names->destinationName($mischief),
                'the pattern "' . $mischief . '" matched a real destination',
            );
        }
    }

    public function testForgettingClearsWhatWasRemembered(): void
    {
        $names = new NameLookup();
        $this->assertSame('United Kingdom', $names->destinationName('gb'));

        Db::exec('DELETE FROM versions WHERE version = ?', [$this->oldVersion]);

        $this->assertSame('United Kingdom', $names->destinationName('gb'), 'still remembered');

        $names->forget();
        $this->assertSame('gb', $names->destinationName('gb'), 'and re-read after forgetting');
    }

    /* ------------------------------------------------------------ helpers */

    /** @param list<array{id: string, name: string}> $destinations */
    private function publish(int $version, array $destinations): void
    {
        $services = [
            ['id' => 'express', 'name' => 'Express'],
            ['id' => 'normal', 'name' => 'Normal'],
        ];

        // Sea freight existed in the older document and was dropped from the
        // newer one, so a service can be retired in this fixture too.
        if ($version === $this->oldVersion) {
            $services[] = ['id' => 'sea', 'name' => 'Sea freight'];
        }

        Db::insert('versions', [
            'version'      => $version,
            'published_at' => Clock::sql(),
            'published_by' => 'tests',
            'source'       => 'test',
            'summary'      => 'NameLookupTest fixture',
            'change_count' => 0,
            'data'         => Db::json([
                'company'      => ['name' => 'Speedat', 'whatsapp' => '923001234567'],
                'services'     => $services,
                'destinations' => $destinations,
                'settings'     => ['currency' => 'PKR'],
            ]),
        ]);
    }
}
