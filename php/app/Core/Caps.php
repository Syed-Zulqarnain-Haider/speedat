<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * How long every stored string is allowed to be, in one table, taken from the
 * schema.
 *
 * WHY THIS EXISTS. db/migrations/0001_init.sql declares VARCHAR widths;
 * app/Core/Db.php connects with STRICT_TRANS_TABLES. Put those two together
 * and a name one character too long is not a truncation and not a validation
 * message - it is an exception out of PDO, which becomes a 500 page, which
 * becomes "the site is broken" from someone who typed a long company name into
 * a contact form. Nothing in the application knew what the widths were, so
 * nothing could refuse the value politely first.
 *
 * ONE PLACE. The numbers below are the schema's numbers.
 * tests/Core/CapsTest.php reads 0001_init.sql, pulls out every VARCHAR width
 * it declares, and fails if any entry here disagrees or if a column named here
 * has gone. Widen a column and the test tells you to widen the cap; widen the
 * cap and it tells you to widen the column. They cannot drift quietly, which
 * is the only property that makes a mirrored constant safe.
 *
 * COUNTED IN CHARACTERS, not bytes, because that is what MySQL counts for a
 * VARCHAR on a utf8mb4 table: VARCHAR(190) holds 190 characters whether they
 * are "e" or "é".
 *
 * ---------------------------------------------------------------------------
 * TWO MORE WAYS A FIELD BECAME A 500, AND WHY LENGTH WAS NEVER THE ONLY ONE
 * ---------------------------------------------------------------------------
 * The docblock above closes the length half and used to leave two holes open
 * (audit round 1, finding 4). Both end in the same place - a PDOException, a 500
 * page, and someone who assumes the site is broken - so both belong here.
 *
 * 1. ENCODING. utf8mb4 plus STRICT_TRANS_TABLES makes a malformed byte an ERROR,
 *    not a substitution. Twelve bytes of `curl --data-binary` at the public
 *    contact form was enough:
 *
 *      Request::post() gave 10 bytes, valid UTF-8: NO
 *      Caps::problems(leads, [name]) says: []
 *      -> SQLSTATE[22007]: 1366 Incorrect string value: '\xC3( Kha...'
 *
 *    Two places now stop it. Core\Request scrubs a malformed field on the way in,
 *    because the request layer is the one place every field passes through and a
 *    broken byte is a broken client rather than a person to argue with. And
 *    problems() checks it again, so a value that reached a write path from
 *    anywhere else is refused with a sentence instead of an exception.
 *
 * 2. TEXT COLUMNS, WHICH HAVE A LIMIT AFTER ALL. This class listed none, and the
 *    docblock said they were "capped by the request caps in Request instead" -
 *    but that cap counts CHARACTERS while TEXT holds 65,535 BYTES. At the default
 *    5,000-character cap the worst case is 20,000 bytes and nothing can happen;
 *    any route that raises its cap above 16,383 can write 65,536 bytes of
 *    perfectly valid emoji into a column that holds 65,535:
 *
 *      TEXT limit check: 20000 chars / 80000 bytes
 *      -> PDOException, and Caps::problems for that message: []
 *
 *    So TEXT_BYTES exists, in BYTES, kept apart from WIDTHS rather than mixed
 *    into it. One map with two units is the kind of thing that reads fine and is
 *    wrong twice a year.
 *
 * THREE WAYS TO USE IT:
 *   - problems() for anything a person typed, where the form can show a message
 *     per field. It returns sentences; the row is never sent to the database.
 *   - assert() where there is no form to decorate - a repository, an admin
 *     action, an API route. One line before the INSERT, and a ValueTooLong
 *     carrying the same sentences if anything is over.
 *   - fit() for anything a machine produced (a User-Agent header, an action
 *     name). Truncating those is right: refusing to write an audit line
 *     because a browser sent a 400-character User-Agent would lose the audit
 *     line, which is the opposite of what the audit is for.
 *
 * WHERE IT MUST BE CALLED, AND IS NOT YET.
 *
 * This class mirrored the whole schema and guarded itself with a test, and for
 * a while that was all it did: Core\Audit called fit(), and problems() was
 * called from nowhere at all. A mirror nothing looks into stops a grand total
 * of zero 500 pages.
 *
 * Below is every write in the tree that touches a capped VARCHAR, found by
 * grepping for the statements rather than remembered, with the line each one
 * needs. The owner of each file adds it; this class cannot reach into them.
 *
 *   app/Domain/Leads/LeadRepository.php      Db::insert('leads', …)
 *       Caps::assert('leads', $row)
 *       kind, quote_id, name, phone, email, dest_id, status, updated_by
 *
 *   app/Domain/Quotes/QuoteRepository.php    INSERT INTO quotes (line 55)
 *       Caps::assert('quotes', $row)
 *       id, dest_id, service_id, type
 *       - and QuoteInput::MAX_DEST_ID (60) / MAX_SERVICE_ID (40) should read
 *         Caps::of('quotes.dest_id') / Caps::of('quotes.service_id') instead of
 *         carrying their own numbers against the columns' 64 and 64
 *
 *   app/Domain/Shipments/ShipmentRepository.php
 *       Db::insert('shipments', …) and Db::insert('shipment_events', …)
 *       Caps::assert('shipments', $row); Caps::assert('shipment_events', $event)
 *       id, quote_id, tracking_no, carrier, customer_name, customer_phone,
 *       receiver_name, dest_id, service_id, status, created_by
 *
 *   app/Domain/SiteDoc/Repo.php              INSERT INTO versions (line 208),
 *                                            INSERT INTO draft (line 222)
 *       Caps::assert('versions', $row); Caps::assert('draft', $row)
 *       published_by, source, updated_by
 *
 *   app/Core/Session.php                     Db::insert('sessions', …)
 *       Caps::assert('sessions', ['email' => $email]) - email only.
 *       user_agent is machine-made and already goes through Caps::fit();
 *       refusing a login because a browser sent a long User-Agent would be
 *       worse than the truncation.
 *
 *   app/Http/ContactController.php           writes leads through the repository
 *       Its MAX_NAME (120) and MAX_PHONE (20) are deliberately STRICTER than
 *       leads.name (190) and leads.phone (40) - a form may ask for less than the
 *       column holds. Left alone; noted so nobody "fixes" them upward.
 *
 * app/Domain/Notify/Alerts.php, Ops/DailyOnce.php and SiteDoc/Hold.php write
 * app_settings with keys this codebase spells itself, so their widths are not a
 * user's to overflow. Core\Audit is already correct: it uses fit(), on purpose.
 *
 * A repository that adopts assert() should also stop carrying its own MAX_*
 * constant for a column named here - two numbers for one column is the drift
 * this class exists to end. tests/Core/CapsTest.php fails the day a local
 * constant is LOOSER than the column it guards.
 */
final class Caps
{
    /**
     * `table.column` => the VARCHAR width in db/migrations/0001_init.sql.
     *
     * VARCHAR only, and COUNTED IN CHARACTERS. TEXT columns are in TEXT_BYTES
     * below, because they are counted in bytes and putting the two in one map
     * would mean the unit of a number depended on which key it was under.
     *
     * @var array<string,int>
     */
    public const WIDTHS = [
        'admins.email'             => 190,
        'admins.name'              => 190,
        'admins.added_by'          => 190,
        'admins.password_hash'     => 255,
        'admins.totp_secret_enc'   => 255,

        'app_settings.k'           => 64,
        'app_settings.updated_by'  => 190,

        'audit_log.actor'          => 190,
        'audit_log.action'         => 64,
        'audit_log.user_agent'     => 300,

        'draft.updated_by'         => 190,

        // THERE IS NO `imports` TABLE any more. Seven caps used to be here for
        // it. The owner removed the Excel rate-sheet feature from the product on
        // 2026-09-27, db/migrations/0001_init.sql says out loud that the table is
        // not ported, and app/Domain/Import is gone - so these were seven numbers
        // guarding nothing, and this class's own drift test is what noticed.

        'leads.kind'               => 16,
        'leads.quote_id'           => 32,
        'leads.name'               => 190,
        'leads.phone'              => 40,
        'leads.email'              => 190,
        'leads.dest_id'            => 64,
        'leads.status'             => 16,
        'leads.updated_by'         => 190,

        // db/migrations/0003. Both strings are machine-made - a bucket name this
        // codebase chooses and a hostname taken apart by parse_url - so the
        // writer uses fit() rather than problems(): there is no form and nobody
        // to tell. They are named here because every VARCHAR in the schema is,
        // and because fit() refuses to guess a width it was never given.
        'page_views.path'          => 190,
        'page_views.device'        => 7,
        'page_views.referrer_host' => 190,

        'quotes.id'                => 32,
        'quotes.dest_id'           => 64,
        'quotes.service_id'        => 64,
        'quotes.type'              => 8,

        'rate_limits.k'            => 160,

        'schema_migrations.filename' => 190,

        'sessions.email'           => 190,
        'sessions.user_agent'      => 300,

        'shipment_events.shipment_id' => 32,
        'shipment_events.status'   => 24,
        'shipment_events.by'       => 190,

        'shipments.id'             => 32,
        'shipments.quote_id'       => 32,
        'shipments.tracking_no'    => 64,
        'shipments.carrier'        => 80,
        'shipments.customer_name'  => 190,
        'shipments.customer_phone' => 40,
        'shipments.receiver_name'  => 190,
        'shipments.dest_id'        => 64,
        'shipments.service_id'     => 64,
        'shipments.status'         => 24,
        'shipments.created_by'     => 190,

        // db/migrations/0004. Every value is machine-made (Tracking\*): a key
        // this code builds, a provider's reason cut with fit(), a sanitised
        // city, an ISO code, a stage word and a hash.
        'shipment_tracking.shipment_id' => 32,
        'shipment_tracking.track_key'   => 120,
        'shipment_tracking.state'       => 16,
        'shipment_tracking.error'       => 190,

        'tracking_checkpoints.shipment_id' => 32,
        'tracking_checkpoints.city'        => 80,
        'tracking_checkpoints.country'     => 2,
        'tracking_checkpoints.stage'       => 24,
        'tracking_checkpoints.fingerprint' => 40,

        'versions.published_by'    => 190,
        'versions.source'          => 255,
    ];

    /**
     * `table.column` => how many BYTES a TEXT column will take.
     *
     * Every TEXT column in db/migrations/0001_init.sql is here;
     * tests/Core/CapsTest.php reads the migration and fails if one is missing, if
     * one named here is not a TEXT column, or if a budget is at or above the hard
     * limit.
     *
     * COUNTED IN BYTES, not characters, because that is what MySQL counts for
     * TEXT: the column holds 65,535 bytes whoever is typing. 60,000 leaves room
     * that nobody writing prose will ever reach and that a byte-counting mistake
     * cannot fall off the end of. A person hitting this limit has pasted a
     * document into a message box, and being told so is better than a 500.
     *
     * @var array<string,int>
     */
    public const TEXT_BYTES = [
        'leads.message'          => 60000,
        'leads.notes'            => 60000,
        'shipment_events.note'   => 60000,
        'shipments.notes'        => 60000,
        'versions.summary'       => 60000,
    ];

    /**
     * What a field is called when the message is read by a customer.
     *
     * "leads.phone is too long" is a message written for the developer.
     * "Phone number" is the label on the form the person is looking at.
     *
     * @var array<string,string>
     */
    private const LABELS = [
        'leads.name'               => 'Name',
        'leads.phone'              => 'Phone number',
        'leads.email'              => 'Email address',
        'shipments.customer_name'  => 'Customer name',
        'shipments.customer_phone' => 'Customer phone number',
        'shipments.receiver_name'  => "Receiver's name",
        'shipments.tracking_no'    => 'Tracking number',
        'shipments.carrier'        => 'Carrier',
        'admins.email'             => 'Email address',
        'admins.name'              => 'Name',
        'leads.message'            => 'Message',
        'leads.notes'              => 'Notes',
        'shipments.notes'          => 'Notes',
        'shipment_events.note'     => 'Note',
        'versions.summary'         => 'Summary',
    ];

    /**
     * The cap for a column.
     *
     * An unknown column throws rather than returning a default: a typo in a
     * column name must not quietly become "no limit", which is exactly the
     * hole this class was written to close.
     */
    public static function of(string $column): int
    {
        if (!isset(self::WIDTHS[$column])) {
            throw new \InvalidArgumentException('No stored width is recorded for ' . $column . '.');
        }

        return self::WIDTHS[$column];
    }

    /**
     * The BYTE budget for a TEXT column.
     *
     * A separate method from of() on purpose: the two return different units, and
     * a caller that has to name which one it wants cannot use the wrong number by
     * accident.
     */
    public static function bytesOf(string $column): int
    {
        if (!isset(self::TEXT_BYTES[$column])) {
            throw new \InvalidArgumentException('No TEXT byte budget is recorded for ' . $column . '.');
        }

        return self::TEXT_BYTES[$column];
    }

    /** Does this value fit? Counted the way the database counts. */
    public static function fits(string $column, ?string $value): bool
    {
        return mb_strlen((string) $value, 'UTF-8') <= self::of($column);
    }

    /**
     * Cut a machine-generated value down to the column. For anything a person
     * typed use problems() instead - silently losing the end of somebody's
     * address is worse than telling them it is too long.
     *
     * SCRUBBED BEFORE IT IS CUT. fit() is the call made where refusing is wrong
     * - the audit line, the page-view path - so a malformed byte must not be
     * allowed to turn it into a 1366 out of PDO either: that would lose the very
     * row fit() exists to keep. Core\Request already scrubs every header and
     * field on the way in; this is the backstop for a value that reached a write
     * from anywhere else (a percent-decoded path such as `/%E9` is one). Scrub
     * first, for the reason Request::clean() gives: mb_strlen() on malformed
     * input is a guess, and the cut must count characters that exist.
     */
    public static function fit(string $column, ?string $value): string
    {
        return Str::limit(mb_scrub((string) $value, 'UTF-8'), self::of($column));
    }

    /**
     * Everything in this row that will not fit, as sentences.
     *
     * Keyed by column so a form can put each message beside its own field. An
     * empty array means the row is safe to insert as far as length goes; it
     * says nothing about whether the values are otherwise sensible, which is
     * each domain's own business.
     *
     * @param  array<string,mixed>  $data column => value, as it would be inserted
     * @return array<string,string> column => message
     */
    public static function problems(string $table, array $data): array
    {
        $problems = [];

        foreach ($data as $column => $value) {
            $key = $table . '.' . $column;

            $isVarchar = isset(self::WIDTHS[$key]);
            $isText    = isset(self::TEXT_BYTES[$key]);

            if (!$isVarchar && !$isText) {
                continue;
            }

            if (!is_string($value)) {
                // null, an int, a float, a bool: not a length question, and not
                // an encoding one either.
                continue;
            }

            // ENCODING BEFORE LENGTH, and it has to be this way round. mb_strlen()
            // on malformed input is already guessing, so a character count taken
            // from bytes the database is about to refuse means nothing - and the
            // person needs to be told the field is unreadable, not that it is
            // eleven characters long.
            if (!mb_check_encoding($value, 'UTF-8')) {
                $problems[$column] = self::label($key)
                    . ' contains characters we could not read. Please retype it.';
                continue;
            }

            if ($isText) {
                // BYTES. TEXT holds 65,535 of them whoever is typing, so a
                // character count would pass 20,000 emoji straight into a column
                // that cannot hold a quarter of them.
                $max = self::TEXT_BYTES[$key];
                $len = strlen($value);

                if ($len > $max) {
                    $problems[$column] = self::label($key) . ' is too long: '
                        . $max . ' bytes at most, and that is ' . $len . '.';
                }

                continue;
            }

            $max = self::WIDTHS[$key];
            $len = mb_strlen($value, 'UTF-8');

            if ($len > $max) {
                $problems[$column] = self::label($key) . ' is too long: '
                    . $max . ' characters at most, and that is ' . $len . '.';
            }
        }

        return $problems;
    }

    /**
     * problems() as one sentence, or null. For a route that shows a single
     * error line rather than per-field messages.
     *
     * @param array<string,mixed> $data
     */
    public static function firstProblem(string $table, array $data): ?string
    {
        $problems = self::problems($table, $data);

        return $problems === [] ? null : reset($problems);
    }

    /**
     * The strict variant: the one line a write path puts before its INSERT.
     *
     *     Caps::assert('leads', $row);
     *     Db::insert('leads', $row);
     *
     * Nothing happens when the row fits, and ValueTooLong carries the same
     * field-by-field sentences problems() would have returned, so a route that
     * wants to decorate a form catches it and reads `$e->problems` rather than
     * calling both.
     *
     * WHY THROWING IS THE RIGHT DEFAULT HERE. Ignoring a return value is one
     * keystroke; ignoring an exception is not. The failure this class exists to
     * prevent is a PDOException out of a write nobody thought to check, and a
     * checker that is easy to forget to check has not moved the problem.
     *
     * @param  array<string,mixed> $data column => value, exactly as it will be inserted
     * @throws ValueTooLong
     */
    public static function assert(string $table, array $data): void
    {
        $problems = self::problems($table, $data);

        if ($problems !== []) {
            throw new ValueTooLong($table, $problems);
        }
    }

    private static function label(string $key): string
    {
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        // "shipment_events.shipment_id" -> "Shipment id". Not pretty, but it
        // names the right field, and every field a customer can reach has a
        // proper label above.
        $column = substr($key, (int) strpos($key, '.') + 1);

        return ucfirst(str_replace('_', ' ', $column));
    }
}
