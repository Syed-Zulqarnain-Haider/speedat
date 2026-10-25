<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

use Speedat\Core\Clock;
use Speedat\Core\Db;

/**
 * Storing and reading the quote log. Port of the database half of
 * src/app/api/quotes/route.ts.
 *
 * UPSERT BY QUOTE ID. The browser fires the log with `keepalive` when Book is
 * tapped and never waits for the answer, so the same id can arrive twice: once
 * when the price was shown and again when the customer acted on it. The second
 * arrival must update the first row, not create a second one, or the same quote
 * would appear twice in the log with different booked flags.
 *
 * ONE DELIBERATE DIFFERENCE FROM THE TYPESCRIPT. Drizzle's
 * `onConflictDoUpdate` there rewrote `booked`, `total` and `detail` but left
 * `billable_g` at whatever the first call stored. A row whose total was
 * computed from one weight and whose billed weight column says another cannot
 * be explained after the fact - and explaining a quote after the next publish
 * is the entire reason the `version` column exists. The billed weight and the
 * version are therefore rewritten with everything else, so the row is always
 * internally consistent. The identity of the quote, its id, is the only thing
 * that cannot change.
 */
final class QuoteRepository
{
    /**
     * `type` is NOT selected: the document rate left the product on 2026-09-27
     * and nothing reads the column any more.
     */
    private const COLUMNS = 'id, created_at, version, dest_id, service_id, billable_g, total, detail, booked';

    /**
     * What goes into the surviving `quotes.type VARCHAR(8) NOT NULL`.
     *
     * The column cannot be dropped from here - db/migrations is not this file's
     * to change - and it has no default, so the INSERT must still name a value.
     * Every row written from now on says "pkg", which is true of every shipment
     * this site prices. A migration should drop the column; until then this
     * constant is the single place that knows it exists.
     */
    private const LEGACY_TYPE = 'pkg';

    private const MAX_LIMIT = 500;

    /*
     * WHAT THE INTEGER COLUMNS HOLD, AND WHY IT IS WRITTEN HERE.
     *
     * app/Core/Db.php connects with STRICT_TRANS_TABLES, so a number wider than
     * its column is not a truncation - MariaDB answers
     * "SQLSTATE[22003]: 1264 Out of range value for column 'billable_g' at row 1"
     * and the customer gets a 503 with a driver message in the log. That is the
     * right answer to an unexpected error and the wrong answer to a value the
     * domain could have refused in a sentence (audit round 1, finding 13).
     *
     * app/Core/Caps.php mirrors the VARCHAR widths and says so - "Only VARCHAR
     * columns are listed" - so nothing in the tree knew these three numbers.
     * They are the schema's own, named by column rather than by line number
     * because a line number rots the next time anybody edits the migration, and
     * tests/Pricing/QuoteLimitsTest.php reads db/migrations/0001_init.sql, finds
     * each column by name and fails if a declared type stops matching the
     * constant guarding it.
     */

    /** `quotes.billable_g INT UNSIGNED NOT NULL`: 0 .. 4,294,967,295 g, i.e. 4,294 tonnes. */
    public const MAX_BILLABLE_G = 4_294_967_295;

    /** `quotes.total INT NOT NULL`, signed. */
    public const MAX_TOTAL = 2_147_483_647;

    /**
     * The floor for a total, which is the DOMAIN's rather than the column's: a
     * signed INT would take -2,147,483,648 quite happily, and this site has no
     * credit note, no refund and no discount that reaches below zero. A negative
     * total is a bug upstream, and a bug upstream is worth a refusal instead of a
     * stored row nobody can explain. Widen it the day a credit exists.
     */
    public const MIN_TOTAL = 0;

    /** `quotes.version INT UNSIGNED NOT NULL`. */
    public const MAX_VERSION = 4_294_967_295;

    /**
     * Write the quote, replacing any earlier version of the same one.
     *
     * @param  array<string,mixed> $detail
     * @throws QuoteRejected       when the database will not take it
     */
    public function upsert(
        string $id,
        int $version,
        string $destId,
        string $serviceId,
        int $billableG,
        int $total,
        array $detail,
        bool $booked,
    ): void {
        self::refuseWhatTheColumnsCannotHold($billableG, $total, $version);

        try {
            Db::exec(
                'INSERT INTO quotes (id, created_at, version, dest_id, service_id, type, billable_g, total, detail, booked)
                      VALUES (:id, :created_at, :version, :dest_id, :service_id, :type, :billable_g, :total, :detail, :booked)
                 ON DUPLICATE KEY UPDATE
                      version    = :version_u,
                      dest_id    = :dest_id_u,
                      service_id = :service_id_u,
                      type       = :type_u,
                      billable_g = :billable_g_u,
                      total      = :total_u,
                      detail     = :detail_u,
                      booked     = :booked_u',
                [
                    ':id'           => $id,
                    ':created_at'   => Clock::sql(),
                    ':version'      => $version,
                    ':dest_id'      => $destId,
                    ':service_id'   => $serviceId,
                    ':type'         => self::LEGACY_TYPE,
                    ':billable_g'   => $billableG,
                    ':total'        => $total,
                    ':detail'       => Db::json($detail),
                    ':booked'       => $booked ? 1 : 0,
                    ':version_u'    => $version,
                    ':dest_id_u'    => $destId,
                    ':service_id_u' => $serviceId,
                    ':type_u'       => self::LEGACY_TYPE,
                    ':billable_g_u' => $billableG,
                    ':total_u'      => $total,
                    ':detail_u'     => Db::json($detail),
                    ':booked_u'     => $booked ? 1 : 0,
                ],
            );
        } catch (\PDOException $e) {
            // The route answers 503 and the customer keeps their price: a quote
            // that could not be logged must never stop a booking.
            throw QuoteRejected::unavailable($e);
        }
    }

    /**
     * The guard in front of the INSERT.
     *
     * Every writer of this table goes through upsert(), so this is the one place
     * that has to know the column widths - the principle the rest of the audit
     * asked for: no caller has to remember.
     *
     * WHY THE WEIGHT GETS THE CARGO ANSWER. A billed weight past the column is
     * past 4,294 tonnes. Whatever produced it - a forged body, an unvalidated
     * rate document, a quantity with too many zeros - the only true thing to say
     * to the person in front of the form is that this is not a parcel, which is
     * what QuoteRejected::overMax() already says ("That weight needs a cargo
     * rate", 409). Domain\Quotes\QuoteService refuses far earlier, at
     * QuoteInput::MAX_GRAMS - one tonne - so in practice this fires only for a
     * caller that skipped that check.
     *
     * WHY THE OTHER TWO DO NOT. A total past a signed INT (PKR 2.1 billion for
     * one shipment) or a version past INT UNSIGNED is not something the customer
     * did or can fix; it means the published document or the caller is wrong. The
     * customer keeps their price and gets "Could not save the quote" (503), the
     * same answer as a database that is down, and the route logs it - never the
     * 400 that would blame them for it.
     *
     * @throws QuoteRejected
     */
    private static function refuseWhatTheColumnsCannotHold(int $billableG, int $total, int $version): void
    {
        // Ranges, not just ceilings: `(int)` of a float above PHP_INT_MAX is
        // undefined in PHP and can come back negative, and a negative gram count
        // is as unstorable in an UNSIGNED column as an over-large one.
        if ($billableG < 0 || $billableG > self::MAX_BILLABLE_G) {
            throw QuoteRejected::overMax();
        }

        if ($total < self::MIN_TOTAL || $total > self::MAX_TOTAL || $version < 0 || $version > self::MAX_VERSION) {
            throw QuoteRejected::unavailable();
        }
    }

    public function find(string $id): ?QuoteRecord
    {
        if (!QuoteId::isValid($id)) {
            return null;
        }

        $row = Db::one('SELECT ' . self::COLUMNS . ' FROM quotes WHERE id = ?', [$id]);

        return $row === null ? null : QuoteRecord::fromRow($row);
    }

    /**
     * The log, newest first.
     *
     * @return list<QuoteRecord>
     */
    public function recent(int $limit = 100, bool $bookedOnly = false): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $rows = $bookedOnly
            ? Db::all('SELECT ' . self::COLUMNS . ' FROM quotes WHERE booked = 1 ORDER BY created_at DESC, id DESC LIMIT ?', [$limit])
            : Db::all('SELECT ' . self::COLUMNS . ' FROM quotes ORDER BY created_at DESC, id DESC LIMIT ?', [$limit]);

        return array_map(QuoteRecord::fromRow(...), $rows);
    }
}
