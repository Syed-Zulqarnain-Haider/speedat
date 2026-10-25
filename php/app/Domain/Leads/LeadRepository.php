<?php

declare(strict_types=1);

namespace Speedat\Domain\Leads;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Str;

/**
 * Reading and writing the inbox. Port of src/lib/leads.ts.
 *
 * EVERY STATEMENT IS PREPARED AND EVERY VALUE IS BOUND. The only strings this
 * class ever puts into SQL are its own column names, from the fixed allow-list
 * in `update()`; nothing a request supplies reaches the SQL text.
 *
 * WHERE THE CAPS COME FROM. Each string is cut to the width of its column in
 * db/migrations/0001_init.sql before it is bound. That is a last line of
 * defence, not the validation: the route that accepts a contact form rejects an
 * over-long name politely, with a message, long before it gets here. What this
 * does is guarantee that no path anywhere can turn a long field into a
 * STRICT_TRANS_TABLES error, which would be a 500 on a form submission. Values
 * that are structurally wrong rather than merely long - an unknown status, a
 * malformed quote id - are refused outright, because truncating those would
 * store something that quietly means the wrong thing.
 */
final class LeadRepository
{
    /* Column widths from 0001_init.sql. */
    private const MAX_NAME     = 190;
    private const MAX_PHONE    = 40;
    private const MAX_EMAIL    = 190;
    private const MAX_DEST_ID  = 64;
    private const MAX_QUOTE_ID = 32;
    private const MAX_ACTOR    = 190;

    /** TEXT columns hold far more; these are what a human could ever mean. */
    private const MAX_MESSAGE = 4000;
    private const MAX_NOTES   = 4000;

    /** weight_g is INT UNSIGNED; a hundred tonnes is already absurd for air freight. */
    private const MAX_WEIGHT_G = 100_000_000;

    private const MAX_LIMIT = 500;

    /**
     * How many words of a search are used. Each is one more AND in the WHERE,
     * and nobody finds a lead by typing a sentence.
     */
    private const MAX_SEARCH_WORDS = 6;

    /** A search with fewer digits than this is not a phone number. */
    private const MIN_PHONE_DIGITS = 4;

    /** Every column, in one place, so a SELECT never drifts from Lead::fromRow(). */
    private const COLUMNS = 'id, created_at, updated_at, kind, quote_id, name, phone, email,
                             message, dest_id, weight_g, status, notes, updated_by, ip_hash';

    /**
     * A contact-form message becomes a lead. Port of `createMessageLead`.
     *
     * Returns the stored row so the caller can quote its id in the office
     * notification and in the audit entry - which is why the TypeScript used
     * `.returning()` and why this re-reads rather than guessing.
     */
    public function createMessageLead(
        string $name,
        string $phone,
        string $email,
        string $message,
        ?string $destId,
        ?int $weightG,
        ?string $ipHash,
    ): Lead {
        $now = Clock::sql();

        $id = Db::insert('leads', [
            'created_at' => $now,
            'updated_at' => $now,
            'kind'       => LeadKind::MESSAGE,
            'quote_id'   => null,
            'name'       => Str::limit(Str::squish($name), self::MAX_NAME),
            'phone'      => Str::limit(Str::squish($phone), self::MAX_PHONE),
            'email'      => Str::limit(Str::squish($email), self::MAX_EMAIL),
            'message'    => Str::limit($message, self::MAX_MESSAGE),
            'dest_id'    => self::destId($destId),
            'weight_g'   => self::weight($weightG),
            'status'     => LeadStatus::NEW,
            'notes'      => '',
            'updated_by' => '',
            'ip_hash'    => self::ipHash($ipHash),
        ]);

        $lead = $this->find($id);
        if ($lead === null) {
            // The insert succeeded and the row is gone: something else is very
            // wrong, and pretending otherwise would hand the caller a lead id
            // that leads nowhere.
            throw new \RuntimeException('The lead was written but could not be read back.');
        }

        return $lead;
    }

    /**
     * A tapped Book button becomes a lead. Port of `upsertQuoteLead`.
     *
     * IDEMPOTENT PER QUOTE ID, which is the whole point: the browser fires this
     * with keepalive and a customer who taps Book twice - or whose phone
     * retries the request on a flaky connection - must be one lead, not two.
     * The unique index on quote_id is what enforces it; ON DUPLICATE KEY UPDATE
     * with a no-op assignment is MySQL's way of spelling Postgres's
     * `onConflictDoNothing`, and unlike INSERT IGNORE it swallows only the
     * duplicate and still raises on anything else that goes wrong.
     *
     * Note what it does NOT do on a repeat: the summary is not rewritten. The
     * first tap's lead is the one the office may already have read and replied
     * to, and quietly rewriting the line under them is worse than a stale one.
     * The quote row itself is upserted separately and does carry the newer
     * numbers.
     *
     * @return bool true when this call created the lead
     */
    public function upsertQuoteLead(
        string $quoteId,
        string $destId,
        int $weightG,
        string $summary,
        string $contents = '',
    ): bool {
        $message = implode("\n", array_values(array_filter([
            trim($summary),
            trim($contents) !== '' ? 'Contents: ' . trim($contents) : '',
        ], static fn (string $l): bool => $l !== '')));

        $now = Clock::sql();

        $affected = Db::exec(
            'INSERT INTO leads (created_at, updated_at, kind, quote_id, name, phone, email,
                                message, dest_id, weight_g, status, notes, updated_by, ip_hash)
                  VALUES (:created_at, :updated_at, :kind, :quote_id, :name, :phone, :email,
                          :message, :dest_id, :weight_g, :status, :notes, :updated_by, :ip_hash)
             ON DUPLICATE KEY UPDATE quote_id = quote_id',
            [
                ':created_at' => $now,
                ':updated_at' => $now,
                ':kind'       => LeadKind::QUOTE,
                ':quote_id'   => self::quoteId($quoteId),
                ':name'       => '',
                ':phone'      => '',
                ':email'      => '',
                ':message'    => Str::limit($message, self::MAX_MESSAGE),
                ':dest_id'    => self::destId($destId),
                ':weight_g'   => self::weight($weightG),
                ':status'     => LeadStatus::NEW,
                ':notes'      => '',
                ':updated_by' => '',
                ':ip_hash'    => null,
            ],
        );

        return $affected === 1;
    }

    /**
     * The inbox list, newest first. Port of `listLeads`.
     *
     * `$offset` is how the inbox reaches past its first screen: the badge on a
     * tab counts every lead in it, and a list that only ever showed the newest
     * 200 left the rest unreachable (fixed 2026-10-25).
     *
     * @param string $status one of LeadStatus::ALL, or LeadStatus::ANY for all
     * @return list<Lead>
     */
    public function list(string $status = LeadStatus::ANY, int $limit = 200, int $offset = 0): array
    {
        return $this->search($status, '', [], $limit, $offset);
    }

    /**
     * The inbox list narrowed by what the office typed into "Find a lead",
     * newest first.
     *
     * WHY THIS IS SQL AND NOT A FILTER OVER THE LIST (fixed 2026-10-25). The
     * search used to run in PHP over the newest 200 rows of the tab, so a
     * customer from three months ago was "Nothing here." on a tab whose badge
     * said 340. Every row the tab holds is searched now.
     *
     * HOW IT MATCHES, so the server finds what admin.js finds as it filters
     * the loaded cards:
     *
     *   - The search is cut into words the way `Str::nameKey` cuts it, and
     *     EVERY word must appear in SOME column: name, phone, email, quote id,
     *     destination id - or the destination's name, which is not in this
     *     table, so the caller passes the ids whose names match (`$destIds`,
     *     word => ids). The columns are utf8mb4_unicode_ci, so LIKE is already
     *     blind to case and accents, the same as nameKey.
     *   - A search that is mostly digits is ALSO compared, digits to digits,
     *     against the phone with everything but digits taken out, in both the
     *     0-form and the 92-form. "03001234567" finds "+92 300 1234567".
     *
     * Every value is bound; the words reach the SQL text only as `?`.
     *
     * @param array<string,list<string>> $destIds word => destination ids whose name contains it
     * @return list<Lead>
     */
    public function search(string $status, string $q, array $destIds = [], int $limit = 200, int $offset = 0): array
    {
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        [$where, $params] = self::where($status, $q, $destIds);

        $rows = Db::all(
            'SELECT ' . self::COLUMNS . ' FROM leads' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );

        return array_map(Lead::fromRow(...), $rows);
    }

    /**
     * How many leads `search()` would find with no limit, for "Showing 1-200
     * of 340".
     *
     * @param array<string,list<string>> $destIds
     */
    public function total(string $status, string $q = '', array $destIds = []): int
    {
        [$where, $params] = self::where($status, $q, $destIds);

        $n = Db::value('SELECT COUNT(*) FROM leads' . $where, $params, 0);

        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * The words a search is matched on: `Str::nameKey`'s, so "Ali-Khan" and
     * "ali khan" are the same search. A search with no Latin letters or digits
     * in it - a name typed in Urdu - is matched whole instead of not at all.
     *
     * @return list<string>
     */
    public static function searchWords(string $q): array
    {
        $key = Str::nameKey($q);

        if ($key === '') {
            $whole = Str::squish($q);

            return $whole === '' ? [] : [Str::limit($whole, 80)];
        }

        return array_slice(array_values(array_unique(explode(' ', $key))), 0, self::MAX_SEARCH_WORDS);
    }

    /**
     * The WHERE for a tab and a search, and what it binds.
     *
     * @param array<string,list<string>> $destIds
     * @return array{0:string,1:list<mixed>}
     */
    private static function where(string $status, string $q, array $destIds): array
    {
        $clauses = [];
        $params  = [];

        if ($status !== LeadStatus::ANY) {
            $clauses[] = 'status = ?';
            $params[]  = self::status($status);
        }

        $words = self::searchWords($q);

        if ($words !== []) {
            $all = [];
            foreach ($words as $word) {
                $like  = '%' . self::escapeLike($word) . '%';
                $any   = [];
                foreach (['name', 'phone', 'email', 'quote_id', 'dest_id'] as $column) {
                    $any[]    = $column . " LIKE ? ESCAPE '!'";
                    $params[] = $like;
                }

                $ids = array_values(array_filter(
                    $destIds[$word] ?? [],
                    static fn (mixed $id): bool => is_string($id) && $id !== '',
                ));
                if ($ids !== []) {
                    $any[]  = 'dest_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                    $params = [...$params, ...$ids];
                }

                $all[] = '(' . implode(' OR ', $any) . ')';
            }

            $match = '(' . implode(' AND ', $all) . ')';

            $forms = self::phoneForms($q);
            if ($forms !== []) {
                $phone = [];
                foreach ($forms as $form) {
                    $phone[]  = "REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?";
                    $params[] = '%' . $form . '%';
                }
                $match = '(' . $match . ' OR ' . implode(' OR ', $phone) . ')';
            }

            $clauses[] = $match;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * A search that reads as a phone number, as the digit strings to look for
     * in a stored phone: "0300 1234567" gives ["03001234567", "923001234567"].
     * Anything with letters in it, or fewer than four digits, gives none.
     *
     * The same prefixes `View\Admin\Wa::digits` undoes, in both directions:
     * a stored number may be in either form, and a digits-in-digits LIKE only
     * finds the form it was given.
     *
     * @return list<string>
     */
    public static function phoneForms(string $q): array
    {
        $q = trim($q);

        if (preg_match('/^[0-9 +().\-]+$/', $q) !== 1) {
            return [];
        }

        $digits = preg_replace('/[^0-9]/', '', $q) ?? '';

        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            return [];
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '920')) {
            $digits = '92' . substr($digits, 3);
        }

        $forms = [$digits];
        if (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $forms[] = '92' . substr($digits, 1);
        } elseif (str_starts_with($digits, '92') && strlen($digits) > 2) {
            $forms[] = '0' . substr($digits, 2);
        }

        return array_values(array_unique(array_filter($forms, static fn (string $f): bool => $f !== '')));
    }

    /** LIKE's two wildcards and the escape character, escaped with "!". */
    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    /** One lead, or null. Port of `getLead`. */
    public function find(int $id): ?Lead
    {
        if ($id < 1) {
            return null;
        }

        $row = Db::one('SELECT ' . self::COLUMNS . ' FROM leads WHERE id = ?', [$id]);

        return $row === null ? null : Lead::fromRow($row);
    }

    /** The lead a quote produced, if Book was ever tapped on it. */
    public function findByQuote(string $quoteId): ?Lead
    {
        $row = Db::one('SELECT ' . self::COLUMNS . ' FROM leads WHERE quote_id = ?', [self::quoteId($quoteId)]);

        return $row === null ? null : Lead::fromRow($row);
    }

    /**
     * Change the status, the notes, or both, and record who did it. Port of
     * `updateLead`.
     *
     * The SET clause is assembled from a fixed allow-list of two columns, so
     * "which columns may be written" is a property of this file and not of what
     * the caller happened to pass.
     *
     * @return Lead|null null when there is no such lead
     */
    public function update(int $id, ?string $status, ?string $notes, string $by): ?Lead
    {
        if ($id < 1) {
            return null;
        }

        $set    = ['updated_by = :by', 'updated_at = :at'];
        $params = [':by' => Str::limit($by, self::MAX_ACTOR), ':at' => Clock::sql(), ':id' => $id];

        if ($status !== null) {
            $set[]             = 'status = :status';
            $params[':status'] = self::status($status);
        }
        if ($notes !== null) {
            $set[]            = 'notes = :notes';
            $params[':notes'] = Str::limit($notes, self::MAX_NOTES);
        }

        Db::exec('UPDATE leads SET ' . implode(', ', $set) . ' WHERE id = :id', $params);

        // Deliberately re-read instead of trusting the row count: MySQL reports
        // zero rows changed when the update set everything to what it already
        // was, which is a successful no-op and not a missing lead.
        return $this->find($id);
    }

    /**
     * Move one lead to a status, used by the shipment desk when a lead is
     * turned into a booking.
     */
    public function setStatus(int $id, string $status, string $by): ?Lead
    {
        return $this->update($id, $status, null, $by);
    }

    /** The badge numbers. Port of `leadCounts`. */
    public function counts(): LeadCounts
    {
        $map = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $row) {
            $map[(string) $row['status']] = (int) $row['n'];
        }

        return LeadCounts::fromMap($map);
    }

    /* ------------------------------------------------------------ validation */

    private static function status(string $status): string
    {
        if (!LeadStatus::isValid($status)) {
            throw new \InvalidArgumentException('Unknown lead status.');
        }

        return $status;
    }

    /**
     * The quote id the lead is keyed by. Refused rather than truncated: a cut
     * id is a different id, and this one carries a unique index.
     */
    private static function quoteId(string $quoteId): string
    {
        $quoteId = trim($quoteId);

        if ($quoteId === '' || strlen($quoteId) > self::MAX_QUOTE_ID) {
            throw new \InvalidArgumentException('A quote id must be 1-' . self::MAX_QUOTE_ID . ' characters.');
        }

        return $quoteId;
    }

    private static function destId(?string $destId): ?string
    {
        if ($destId === null) {
            return null;
        }

        $destId = trim($destId);

        return $destId === '' ? null : Str::limit($destId, self::MAX_DEST_ID);
    }

    /** Out-of-range weights become "no weight given" rather than a column error. */
    private static function weight(?int $weightG): ?int
    {
        if ($weightG === null || $weightG < 1 || $weightG > self::MAX_WEIGHT_G) {
            return null;
        }

        return $weightG;
    }

    /** ip_hash is CHAR(32) ascii holding hex; anything else is not one. */
    private static function ipHash(?string $ipHash): ?string
    {
        if ($ipHash === null || $ipHash === '') {
            return null;
        }

        return preg_match('/^[0-9a-f]{32}$/', $ipHash) === 1 ? $ipHash : null;
    }
}
