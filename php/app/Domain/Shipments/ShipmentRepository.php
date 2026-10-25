<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Str;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Leads\LeadStatus;

/**
 * Reading and writing shipments and their timelines. Port of
 * src/lib/shipments.ts.
 *
 * THREE THINGS THIS CLASS GUARANTEES.
 *
 * 1. A SHIPMENT ALWAYS HAS AT LEAST ONE EVENT. The row and its first "booked"
 *    event are written in one transaction, so a shipment whose timeline is
 *    empty cannot exist and no page has to handle one that does.
 *
 * 2. THE STATUS COLUMN IS THE STATUS OF THE NEWEST EVENT. Both are written
 *    together, under a row lock, so the headline and the timeline can never
 *    disagree - and the customer's WhatsApp update, which reads both, can never
 *    put an old note under a new headline.
 *
 * 3. ONLY A TRANSITION THE TABLE ALLOWS IS WRITTEN. See ShipmentStatus: the
 *    Next.js app accepted whatever the form posted, so one mis-click sent
 *    "Your shipment has been delivered." about a parcel still on the shelf.
 *    A refused move raises InvalidTransition and nothing is written.
 *
 * Every statement is prepared and every value bound. `by` is a reserved word in
 * MySQL, so it is written in backticks everywhere it appears.
 */
final class ShipmentRepository
{
    /* Column widths from db/migrations/0001_init.sql. */
    private const MAX_ID          = 32;
    private const MAX_TRACKING    = 64;
    private const MAX_CARRIER     = 80;
    private const MAX_PERSON      = 190;
    private const MAX_PHONE       = 40;
    private const MAX_DEST_ID     = 64;
    private const MAX_SERVICE_ID  = 64;
    private const MAX_ACTOR       = 190;
    private const MAX_NOTES       = 4000;
    private const MAX_EVENT_NOTE  = 1000;
    private const MAX_LIMIT       = 500;

    /** How many times to re-roll an id before admitting something is wrong. */
    private const ID_ATTEMPTS = 5;

    /** The filter value meaning "everything that is not delivered". */
    public const OPEN = 'open';

    /** The filter value meaning "no filter". */
    public const ANY = 'all';

    private const COLUMNS = 'id, created_at, updated_at, lead_id, quote_id, tracking_no, carrier,
                             customer_name, customer_phone, receiver_name, dest_id, service_id,
                             status, notes, created_by';

    public function __construct(private readonly LeadRepository $leads = new LeadRepository())
    {
    }

    /**
     * Create a shipment, from a lead, from a quote, or from nothing at all.
     * Port of `createShipment`.
     *
     * The destination and service ids are stored as given: whether they exist
     * on the live rate card is the caller's check (the admin form makes it), and
     * a shipment must remain valid after the country it was booked to is taken
     * off the card - which is what NameLookup is for.
     */
    public function create(
        ?int $leadId,
        ?string $quoteId,
        string $customerName,
        string $customerPhone,
        string $receiverName,
        string $destId,
        string $serviceId,
        string $carrier,
        ?string $trackingNo,
        string $notes,
        string $by,
    ): Shipment {
        $destId    = self::required($destId, self::MAX_DEST_ID, 'destination');
        $serviceId = self::required($serviceId, self::MAX_SERVICE_ID, 'service');
        $actor     = Str::limit($by, self::MAX_ACTOR);
        $now       = Clock::sql();

        return Db::transaction(function () use (
            $leadId, $quoteId, $customerName, $customerPhone, $receiverName,
            $destId, $serviceId, $carrier, $trackingNo, $notes, $actor, $now
        ): Shipment {
            $id = $this->freeId();

            Db::insert('shipments', [
                'id'             => $id,
                'created_at'     => $now,
                'updated_at'     => $now,
                'lead_id'        => $leadId !== null && $leadId > 0 ? $leadId : null,
                'quote_id'       => self::optional($quoteId, self::MAX_ID),
                'tracking_no'    => self::optional($trackingNo, self::MAX_TRACKING),
                'carrier'        => Str::limit(Str::squish($carrier), self::MAX_CARRIER),
                'customer_name'  => Str::limit(Str::squish($customerName), self::MAX_PERSON),
                'customer_phone' => Str::limit(Str::squish($customerPhone), self::MAX_PHONE),
                'receiver_name'  => Str::limit(Str::squish($receiverName), self::MAX_PERSON),
                'dest_id'        => $destId,
                'service_id'     => $serviceId,
                'status'         => ShipmentStatus::BOOKED,
                'notes'          => Str::limit($notes, self::MAX_NOTES),
                'created_by'     => $actor,
            ]);

            $this->writeEvent($id, ShipmentStatus::BOOKED, '', $actor, $now);

            // The lead that produced this shipment is now booked. It is inside
            // the transaction on purpose: a shipment that exists while its lead
            // still says "new" is a job two people will call the customer about.
            if ($leadId !== null && $leadId > 0) {
                $this->leads->setStatus($leadId, LeadStatus::BOOKED, $actor);
            }

            $shipment = $this->find($id);
            if ($shipment === null) {
                throw new \RuntimeException('The shipment was written but could not be read back.');
            }

            return $shipment;
        });
    }

    /**
     * Change the details - not the status. Port of `updateShipment`.
     *
     * Only the six columns named here can be written, from a fixed allow-list,
     * and a null patch value means "leave it alone" rather than "set it to
     * null". The one exception is the tracking number, where an empty string
     * genuinely means "we do not have one", so it is stored as NULL.
     *
     * @param array<string,string|null> $patch keys among customerName,
     *        customerPhone, receiverName, carrier, trackingNo, notes
     */
    public function update(string $id, array $patch, string $by): ?Shipment
    {
        $id = self::id($id);

        $columns = [
            'customerName'  => ['customer_name', self::MAX_PERSON],
            'customerPhone' => ['customer_phone', self::MAX_PHONE],
            'receiverName'  => ['receiver_name', self::MAX_PERSON],
            'carrier'       => ['carrier', self::MAX_CARRIER],
            'trackingNo'    => ['tracking_no', self::MAX_TRACKING],
            'notes'         => ['notes', self::MAX_NOTES],
        ];

        $set    = ['updated_at = :at'];
        $params = [':at' => Clock::sql(), ':id' => $id];

        foreach ($patch as $key => $value) {
            if (!isset($columns[$key])) {
                throw new \InvalidArgumentException('A shipment has no editable field called "' . $key . '".');
            }
            if ($value === null) {
                continue;
            }

            [$column, $max] = $columns[$key];

            $clean = $key === 'notes' ? Str::limit($value, $max) : Str::limit(Str::squish($value), $max);

            $set[]                   = $column . ' = :' . $column;
            $params[':' . $column] = $key === 'trackingNo' && $clean === '' ? null : $clean;
        }

        if (count($set) === 1) {
            // Nothing to change; do not bump updated_at for an empty edit.
            return $this->find($id);
        }

        // `by` is recorded on the events, not here: the Next.js version took it
        // and threw it away (`void by`), and a "last edited by" column that is
        // not shown anywhere is a column that goes stale in silence.
        Db::exec('UPDATE shipments SET ' . implode(', ', $set) . ' WHERE id = :id', $params);

        return $this->find($id);
    }

    /**
     * Move a shipment to a status and write the event that says so. Port of
     * `addShipmentEvent`, with the transition table added.
     *
     * @throws InvalidTransition when the move is not one the table allows
     * @return ShipmentEvent|null null when there is no such shipment
     */
    public function addEvent(string $id, string $status, string $note, string $by): ?ShipmentEvent
    {
        $id     = self::id($id);
        $status = self::status($status);
        $actor  = Str::limit($by, self::MAX_ACTOR);
        $note   = Str::limit($note, self::MAX_EVENT_NOTE);

        return Db::transaction(function () use ($id, $status, $note, $actor): ?ShipmentEvent {
            // FOR UPDATE: two admins pressing two different buttons at the same
            // moment must not both read "in_transit" and both be allowed.
            $current = Db::value('SELECT status FROM shipments WHERE id = ? FOR UPDATE', [$id]);

            if (!is_string($current)) {
                return null;
            }

            $from = ShipmentStatus::orBooked($current);
            if (!ShipmentStatus::canMove($from, $status)) {
                throw new InvalidTransition($from, $status);
            }

            $now = Clock::sql();

            Db::exec('UPDATE shipments SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $id]);

            $eventId = $this->writeEvent($id, $status, $note, $actor, $now);

            $row = Db::one(
                'SELECT id, shipment_id, status, note, at, `by` FROM shipment_events WHERE id = ?',
                [$eventId],
            );

            return $row === null ? null : ShipmentEvent::fromRow($row);
        });
    }

    /**
     * The desk list, most recently touched first. Port of `listShipments`.
     *
     * THE SEARCH IS HERE, BEFORE THE LIMIT, AND NOT IN THE CALLER AFTER IT.
     * The controller used to fetch the 200 most recently touched rows and
     * filter those in PHP, so a customer ringing about a parcel delivered three
     * months ago was told "No shipments here" while the row sat in the table -
     * and the empty list's own advice was to create it again. A search that
     * cannot see past the first page is not a search.
     *
     * HOW IT MATCHES - the inbox's way (LeadRepository::search), so the two
     * desks find the same things for the same typing:
     *
     *   - The search is cut into words the way `Str::nameKey` cuts it
     *     (LeadRepository::searchWords), and EVERY word must appear in SOME
     *     column: the shipment number, the quote it came from, the customer,
     *     the carrier, the tracking number - or the destination's name. A
     *     single substring over the raw text, as this first was, lost two
     *     things the old PHP filter's nameKey had: "SH ABCD2345", which is how
     *     a number is read down the phone, no longer found SH-ABCD2345, and
     *     "ali canada" (a customer and where it went) found nothing at all.
     *   - What was typed is ALSO run through ShipmentId::normalise, and if it
     *     is a shipment number in any spelling - "shabcd2345", "SH ABCD 2345" -
     *     that row matches outright, by its key.
     *
     * The destination is stored as an id, so a country NAME cannot be matched
     * in SQL; the caller resolves the names it knows to ids, per word, and
     * passes them as `$destIds` (word => ids). `%` and `_` in a word are
     * escaped - a search with no Latin letters or digits is matched whole, and
     * "50%" in it is a search for "50%", not for everything beginning with 50.
     * Every value is bound; the words reach the SQL only as `?`.
     *
     * @param string       $status  a ShipmentStatus, or self::OPEN / self::ANY
     * @param array<string,list<string>> $destIds word => destinations whose name contains it
     * @return list<Shipment>
     */
    public function list(string $status = self::OPEN, int $limit = 200, string $search = '', array $destIds = []): array
    {
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $where  = [];
        $params = [];

        if ($status === self::OPEN) {
            $where[]  = 'status <> ?';
            $params[] = ShipmentStatus::DELIVERED;
        } elseif ($status !== self::ANY) {
            $where[]  = 'status = ?';
            $params[] = self::status($status);
        }

        $search = Str::limit(Str::squish($search), self::MAX_PERSON);
        $words  = LeadRepository::searchWords($search);

        if ($words !== []) {
            // The words' own binds, kept apart: the id alternative below goes
            // in FRONT of them in the SQL, so its `?` must come first here too.
            $all   = [];
            $binds = [];
            foreach ($words as $word) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';

                // `id` is ascii_bin - case-sensitive, and a non-ASCII pattern is
                // an "illegal mix of collations" against it - so it is compared
                // in the table's own case-insensitive collation like every other
                // column. No ESCAPE clause: backslash is the default one, and
                // sql_mode (Db) does not set NO_BACKSLASH_ESCAPES.
                $any = [
                    'CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?',
                    'quote_id LIKE ?',
                    'customer_name LIKE ?',
                    'carrier LIKE ?',
                    'tracking_no LIKE ?',
                ];
                array_push($binds, $like, $like, $like, $like, $like);

                $ids = array_values(array_unique(array_filter(
                    array_map(
                        static fn (mixed $d): string => is_string($d) ? Str::limit($d, self::MAX_DEST_ID) : '',
                        is_array($destIds[$word] ?? null) ? $destIds[$word] : [],
                    ),
                    static fn (string $d): bool => $d !== '',
                )));
                if ($ids !== []) {
                    $any[] = 'dest_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                    array_push($binds, ...$ids);
                }

                $all[] = '(' . implode(' OR ', $any) . ')';
            }

            $match = '(' . implode(' AND ', $all) . ')';

            // "SHABCD2345" is one word that is in no column as typed; the
            // number it spells is. Compared by the key, so it costs nothing.
            $id = ShipmentId::normalise($search);
            if ($id !== null) {
                $match = '(id = ? OR ' . $match . ')';
                array_unshift($binds, $id);
            }

            $where[] = $match;
            array_push($params, ...$binds);
        }

        $params[] = $limit;

        $rows = Db::all(
            'SELECT ' . self::COLUMNS . ' FROM shipments'
                . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
                . ' ORDER BY updated_at DESC, id DESC LIMIT ?',
            $params,
        );

        return array_map(Shipment::fromRow(...), $rows);
    }

    public function find(string $id): ?Shipment
    {
        $row = Db::one('SELECT ' . self::COLUMNS . ' FROM shipments WHERE id = ?', [self::id($id)]);

        return $row === null ? null : Shipment::fromRow($row);
    }

    /** The shipment and its timeline, oldest event first. Port of `getShipment`. */
    public function history(string $id): ?ShipmentHistory
    {
        $shipment = $this->find($id);
        if ($shipment === null) {
            return null;
        }

        $rows = Db::all(
            'SELECT id, shipment_id, status, note, at, `by`
               FROM shipment_events
              WHERE shipment_id = ?
              ORDER BY at ASC, id ASC',
            [$shipment->id],
        );

        return new ShipmentHistory($shipment, array_map(ShipmentEvent::fromRow(...), $rows));
    }

    /**
     * The two numbers the navigation shows. Port of `shipmentCounts`.
     *
     * @return array{open: int, exception: int}
     */
    public function counts(): array
    {
        $open      = 0;
        $exception = 0;

        foreach (Db::all('SELECT status, COUNT(*) AS n FROM shipments GROUP BY status') as $row) {
            $status = (string) $row['status'];
            $n      = (int) $row['n'];

            if ($status !== ShipmentStatus::DELIVERED) {
                $open += $n;
            }
            if ($status === ShipmentStatus::EXCEPTION) {
                $exception = $n;
            }
        }

        return ['open' => $open, 'exception' => $exception];
    }

    /* ------------------------------------------------------------ internals */

    private function writeEvent(string $id, string $status, string $note, string $by, string $at): int
    {
        // `by` is reserved in MySQL; Db::insert() backtick-quotes every column
        // it is given, which is exactly why this goes through it.
        return Db::insert('shipment_events', [
            'shipment_id' => $id,
            'status'      => $status,
            'note'        => $note,
            'at'          => $at,
            'by'          => $by,
        ]);
    }

    /**
     * An id nothing is using yet.
     *
     * Eight characters from a thirty-letter alphabet collide about once in
     * 6.5e11, so five attempts is not a retry loop so much as a promise that the
     * insert below cannot fail on the primary key. Running out means something
     * is badly wrong - an exhausted entropy source, say - and that is worth an
     * exception rather than a sixth roll.
     */
    private function freeId(): string
    {
        for ($i = 0; $i < self::ID_ATTEMPTS; $i++) {
            $id = ShipmentId::generate();

            if (Db::value('SELECT 1 FROM shipments WHERE id = ?', [$id]) === null) {
                return $id;
            }
        }

        throw new \RuntimeException('Could not find an unused shipment id.');
    }

    private static function id(string $id): string
    {
        $id = strtoupper(trim($id));

        if (!ShipmentId::isValid($id)) {
            throw new \InvalidArgumentException('That is not a shipment id.');
        }

        return $id;
    }

    private static function status(string $status): string
    {
        if (!ShipmentStatus::isValid($status)) {
            throw new \InvalidArgumentException('Unknown shipment status.');
        }

        return $status;
    }

    private static function required(string $value, int $max, string $what): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException('A shipment needs a ' . $what . '.');
        }

        return Str::limit($value, $max);
    }

    private static function optional(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $max);
    }
}
