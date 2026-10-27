<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\Domain\Shipments\Shipment;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Domain\Tracking\Checkpoint;
use Speedat\Domain\Tracking\Stage;
use Speedat\Domain\Tracking\TrackingRepository;

/**
 * GET /track, through the real routing table and a real database.
 *
 * The page is a stranger's window onto one parcel, keyed on nothing but the
 * shipment number. So most of what is checked here is what it must NOT do:
 * print a name, a phone, a note, the carrier's own tracking number or any
 * carrier text the office typed by hand; tell a malformed number from an
 * unknown one; let one address try numbers without limit; or be indexed.
 *
 * Every row a test writes is written under an id it remembers, and deleted
 * again in tearDown() - shipments, their events, any tracking state and
 * checkpoints - with the `track:` rate-limit buckets emptied on both sides.
 */
final class TrackPageTest extends HttpTestCase
{
    private const ACTOR = 'office@speedat.test';

    /** What a lookup that finds nothing says, whatever was wrong with the number. */
    private const NOT_FOUND = 'We could not find that shipment number.';

    private ShipmentRepository $shipments;

    /** @var list<string> */
    private array $created = [];

    private mixed $savedLimit = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->savedLimit = Config::get('limits.track_per_hour');
        $this->shipments  = new ShipmentRepository();
        $this->cleanLimits();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        Config::set('limits.track_per_hour', $this->savedLimit);

        if (Db::healthy()) {
            foreach ($this->created as $id) {
                Db::exec('DELETE FROM tracking_checkpoints WHERE shipment_id = ?', [$id]);
                Db::exec('DELETE FROM shipment_tracking WHERE shipment_id = ?', [$id]);
                Db::exec('DELETE FROM shipment_events WHERE shipment_id = ?', [$id]);
                Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
            }
        }
        $this->created = [];

        $this->cleanLimits();
    }

    private function cleanLimits(): void
    {
        if (Db::healthy()) {
            Db::exec("DELETE FROM rate_limits WHERE k LIKE 'track:%'");
        }
    }

    private function needDatabase(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }
    }

    /* ===================================================================== */
    /*  the empty form                                                       */
    /* ===================================================================== */

    public function testTheEmptyFormAnswers200WithTheBoxAndNoRefresh(): void
    {
        $answer = $this->get('/track');

        $this->assertStatus(200, $answer);
        $this->assertContentType('text/html; charset=utf-8', $answer);
        $this->assertStringContains('<form class="track-form" method="get"', $answer->body());
        $this->assertStringContains('name="id"', $answer->body(), 'the shipment number box');
        $this->assertOneH1($answer);
        $this->assertSame('', $answer->header('Refresh'), 'a page with nothing on it has nothing to refresh');
        $this->assertStringContains('no-store', $answer->header('Cache-Control'));
        $this->assertStringNotContains(self::NOT_FOUND, $answer->body(), 'nothing was looked up, so nothing was missing');
    }

    /** One customer's parcel has no business in a search engine. */
    public function testThePageIsNoindex(): void
    {
        $body = $this->get('/track')->body();

        $this->assertStringContains('<meta name="robots" content="noindex', $body);
        $this->assertStringNotContains('<meta name="robots" content="index', $body);
    }

    public function testThePageIsNotInTheSitemap(): void
    {
        $sitemap = $this->get('/sitemap.xml');

        $this->assertStatus(200, $sitemap);
        $this->assertStringNotContains('/track', $sitemap->body());
    }

    /**
     * Deliberately NOT disallowed: a crawler that may not fetch the page can
     * never read the noindex on it, and keeps the bare URL in its index.
     */
    public function testRobotsTxtDoesNotDisallowTheTrackPage(): void
    {
        $text = $this->get('/robots.txt')->body();

        preg_match_all('/^\s*Disallow:\s*(\S*)\s*$/mi', $text, $m);
        foreach ($m[1] as $rule) {
            if ($rule === '') {
                continue;
            }
            $this->assertFalse(str_starts_with('/track', $rule), 'robots.txt blocks /track with "Disallow: ' . $rule . '"');
        }
    }

    public function testTheOldTrackingPageRedirectsHere(): void
    {
        $answer = $this->get('/tracking.html');

        $this->assertStatus(301, $answer);
        $this->assertSame('/track', $answer->header('Location'));
    }

    /* ===================================================================== */
    /*  a number that finds nothing                                          */
    /* ===================================================================== */

    /**
     * A well-formed number that does not exist and a string that could never
     * be a number read the same, so the page cannot be used to sort real
     * numbers from impossible ones.
     */
    public function testAnUnknownNumberAndAMalformedOneAreTheSameNotFound(): void
    {
        $this->needDatabase();

        $unknown   = $this->get('/track', ['id' => $this->unusedId()]);
        $malformed = $this->get('/track', ['id' => 'hello']);

        $this->assertStatus(404, $unknown, 'unknown');
        $this->assertStatus(404, $malformed, 'malformed');
        $this->assertStringContains(self::NOT_FOUND, $unknown->body());
        $this->assertSame($this->notice($unknown), $this->notice($malformed), 'the two refusals must not differ');
        $this->assertSame('', $unknown->header('Refresh'));
        $this->assertSame('', $malformed->header('Refresh'));

        // The visitor's own typing goes back in the box, escaped.
        $this->assertStringContains('name="id" value="hello"', $malformed->body());
        $this->assertStringContains('aria-invalid="true"', $malformed->body());
    }

    public function testWhatWasTypedIsEscapedOnTheWayBack(): void
    {
        $this->needDatabase();

        $answer = $this->get('/track', ['id' => '"><script>x</script>']);

        $this->assertStatus(404, $answer);
        $this->assertStringNotContains('<script>x</script>', $answer->body());
    }

    /* ===================================================================== */
    /*  a number that finds a parcel                                         */
    /* ===================================================================== */

    public function testANumberTypedInLowerCaseWithSpacesStillFindsTheShipment(): void
    {
        $this->needDatabase();

        $shipment = $this->create();
        // "SH-ABCD2345" typed as "sh abcd 2345".
        $typed = strtolower('sh ' . substr($shipment->id, 3, 4) . ' ' . substr($shipment->id, 7));

        $answer = $this->get('/track', ['id' => $typed]);

        $this->assertStatus(200, $answer, $typed);
        $this->assertStringContains('<strong>' . $shipment->id . '</strong>', $answer->body(), 'the page names the shipment as stored');

        // Without the prefix at all.
        $this->assertStatus(200, $this->get('/track', ['id' => substr($shipment->id, 3)]), 'no SH- prefix');
    }

    public function testAnOpenShipmentShowsItsStatusAndDestinationAndRefreshesItself(): void
    {
        $this->needDatabase();

        $shipment = $this->create(destId: 'ca');
        $this->shipments->addEvent($shipment->id, ShipmentStatus::IN_TRANSIT, '', self::ACTOR);

        $answer = $this->get('/track', ['id' => $shipment->id]);
        $body   = $answer->body();

        $this->assertStatus(200, $answer);
        $this->assertStringContains('id="track-status">' . ShipmentStatus::label(ShipmentStatus::IN_TRANSIT) . '</h2>', $body);
        $this->assertStringContains(htmlspecialchars(ShipmentStatus::customerText(ShipmentStatus::IN_TRANSIT), ENT_QUOTES), $body);

        $destination = (new NameLookup())->destinationName('ca');
        $this->assertNotSame('', $destination);
        $this->assertStringContains(' to ' . htmlspecialchars($destination, ENT_QUOTES), $body);

        $this->assertSame('1800', $answer->header('Refresh'), 'an open shipment reloads itself every half hour');
        $this->assertStringContains('This page updates by itself.', $body);
        $this->assertStringContains('no-store', $answer->header('Cache-Control'), 'a shared phone must not show the last parcel from cache');
        $this->assertOneH1($answer);
        $this->assertStringNotContains(self::NOT_FOUND, $body);
    }

    public function testADeliveredShipmentStopsRefreshing(): void
    {
        $this->needDatabase();

        $shipment = $this->create();
        foreach ([ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::DELIVERED] as $status) {
            $this->shipments->addEvent($shipment->id, $status, '', self::ACTOR);
        }

        $answer = $this->get('/track', ['id' => $shipment->id]);

        $this->assertStatus(200, $answer);
        $this->assertStringContains('id="track-status">Delivered</h2>', $answer->body());
        $this->assertSame('', $answer->header('Refresh'), 'nothing more is coming for a delivered parcel');
        $this->assertStringNotContains('This page updates by itself.', $answer->body());
        $this->assertStringContains('no-store', $answer->header('Cache-Control'));
    }

    /* ===================================================================== */
    /*  privacy                                                              */
    /* ===================================================================== */

    /**
     * THE PAGE PRINTS NOTHING A PERSON TYPED about the parcel. Every free-text
     * field on the shipment carries a marker; none of them may reach the body.
     * What may: the carrier's scanned city, and the carrier's name when it is
     * one we know.
     */
    public function testTheResultShowsNoNamePhoneNoteOrTrackingNumber(): void
    {
        $this->needDatabase();

        $shipment = $this->shipments->create(
            null,
            null,
            'Zubaida Qwertyname',
            '+92 345 9182736',
            'Receivero Plimsoll',
            'ca',
            'express',
            'DHL Express',
            'JD014600003829471',
            'Internal: back gate code 7731, call Uncle Farooq',
            self::ACTOR,
        );
        $this->created[] = $shipment->id;

        $this->shipments->addEvent($shipment->id, ShipmentStatus::IN_TRANSIT, 'Handed to Mr Secretnote at the counter', self::ACTOR);

        (new TrackingRepository())->addCheckpoints($shipment->id, [
            new Checkpoint(new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC')), 'Leipzig', 'DE', Stage::IN_TRANSIT, sha1('track-page-test-1-' . $shipment->id)),
            new Checkpoint(new \DateTimeImmutable('2026-10-21 09:30:00', new \DateTimeZone('UTC')), 'Toronto', 'CA', Stage::CUSTOMS, sha1('track-page-test-2-' . $shipment->id)),
        ]);

        $answer = $this->get('/track', ['id' => $shipment->id]);
        $body   = $answer->body();

        $this->assertStatus(200, $answer);

        foreach ([
            'customer name'   => 'Qwertyname',
            'customer phone'  => '9182736',
            'phone, as typed' => '345 9182736',
            'receiver name'   => 'Plimsoll',
            'notes'           => 'Uncle Farooq',
            'notes code'      => '7731',
            'event note'      => 'Secretnote',
            'tracking number' => 'JD014600003829471',
        ] as $what => $secret) {
            $this->assertStringNotContains($secret, $body, 'the ' . $what . ' leaked onto the public page');
        }

        $this->assertStringContains('Leipzig, DE', $body, 'the scanned city is shown');
        $this->assertStringContains('Toronto, CA', $body);
        $this->assertStringContains(Stage::label(Stage::CUSTOMS), $body);
        $this->assertStringContains('Carried by DHL Express', $body);
    }

    /** A carrier text we do not recognise is something a person typed, so it stays off the page. */
    public function testACarrierWeDoNotKnowIsNotPrinted(): void
    {
        $this->needDatabase();

        $shipment = $this->create(carrier: "Ali's cousin cargo");

        $answer = $this->get('/track', ['id' => $shipment->id]);

        $this->assertStatus(200, $answer);
        $this->assertStringNotContains('cousin', $answer->body());
        $this->assertStringNotContains('Carried by', $answer->body());
    }

    public function testAKnownCarrierTypedLooselyIsShownByItsProperName(): void
    {
        $this->needDatabase();

        $shipment = $this->create(carrier: 'dhl');

        $this->assertStringContains('Carried by DHL Express', $this->get('/track', ['id' => $shipment->id])->body());
    }

    /* ===================================================================== */
    /*  rate limit                                                           */
    /* ===================================================================== */

    /**
     * limits.track_per_hour lookups per address, then 429. The empty form is
     * not a lookup and costs nothing, before or after the limit.
     */
    public function testLookupsBeyondTheHourlyLimitAreRefusedButTheEmptyFormIsFree(): void
    {
        $this->needDatabase();

        Config::set('limits.track_per_hour', 3);

        for ($i = 0; $i < 5; $i++) {
            $this->assertStatus(200, $this->get('/track'), 'empty form ' . $i);
        }

        for ($i = 1; $i <= 3; $i++) {
            $this->assertStatus(404, $this->get('/track', ['id' => 'hello']), 'lookup ' . $i);
        }

        $refused = $this->get('/track', ['id' => 'hello']);
        $this->assertStatus(429, $refused, 'lookup 4');
        $this->assertNotSame('', $refused->header('Retry-After'), 'a 429 says when to come back');

        $this->assertStatus(200, $this->get('/track'), 'the empty form still answers once the lookups are spent');
    }

    /* ===================================================================== */
    /*  helpers                                                              */
    /* ===================================================================== */

    private function create(string $destId = 'ca', string $carrier = '', string $trackingNo = ''): Shipment
    {
        $shipment        = $this->shipments->create(
            null,
            null,
            'Track Page Test',
            '03001234567',
            'Track Receiver',
            $destId,
            'express',
            $carrier,
            $trackingNo,
            '',
            self::ACTOR,
        );
        $this->created[] = $shipment->id;

        return $shipment;
    }

    /** A well-formed shipment number that is not in the table. */
    private function unusedId(): string
    {
        do {
            $id = \Speedat\Domain\Shipments\ShipmentId::generate();
        } while ($this->shipments->find($id) !== null);

        return $id;
    }

    /** The not-found box's text, whitespace squeezed. */
    private function notice(Answer $answer): string
    {
        if (preg_match('#<div class="notice err" id="track-missing" role="alert">(.*?)</div>#s', $answer->body(), $m) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $m[1]));
    }
}
