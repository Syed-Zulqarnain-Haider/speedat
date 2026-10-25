<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\ValueTooLong;
use Speedat\Tests\TestCase;

/**
 * Input caps against the schema.
 *
 * The bug this suite is here to prevent: db/migrations/0001_init.sql says
 * VARCHAR(190), app/Core/Db.php connects with STRICT_TRANS_TABLES, and nothing
 * in between knew the number. A name one character too long was therefore not
 * a message next to the field - it was a PDOException, a 500 page, and a
 * customer who assumes the site is broken.
 *
 * So two things are checked here. That every width in Caps::WIDTHS is the
 * width the schema actually declares (read out of the .sql, not remembered),
 * and that an over-long value is refused in words before anything is sent to
 * the database.
 */
final class CapsTest extends TestCase
{
    public function testEveryCapIsTheWidthTheSchemaDeclares(): void
    {
        $schema = $this->schemaWidths();

        $this->assertTrue(count($schema) > 30, 'the migration was parsed, not skipped: found ' . count($schema));

        $wrong   = [];
        $missing = [];

        foreach (Caps::WIDTHS as $column => $cap) {
            if (!isset($schema[$column])) {
                $missing[] = $column;
                continue;
            }
            if ($schema[$column] !== $cap) {
                $wrong[] = $column . ': schema says ' . $schema[$column] . ', Caps says ' . $cap;
            }
        }

        $this->assertSame([], $missing, 'capped columns that are not in the schema: ' . implode(', ', $missing));
        $this->assertSame([], $wrong, implode('; ', $wrong));
    }

    /**
     * And the other direction: a VARCHAR the application writes to and has no
     * cap for is the hole this class was written to close, so new columns are
     * named out loud.
     *
     * Only the tables the application inserts into are required. Columns whose
     * value never comes from a person or a request (a password hash, a
     * checksum) are listed as exceptions with the reason.
     */
    public function testEveryVarcharTheApplicationWritesToHasACap(): void
    {
        $exceptions = [
            // Not written by this application at all.
            'schema_migrations.filename' => 'written by bin/migrate.php from a filename on disk',
        ];

        $uncapped = [];

        foreach ($this->schemaWidths() as $column => $width) {
            if (isset(Caps::WIDTHS[$column]) || isset($exceptions[$column])) {
                continue;
            }

            $uncapped[] = $column . ' VARCHAR(' . $width . ')';
        }

        $this->assertSame(
            [],
            $uncapped,
            'add these to Caps::WIDTHS (or to the exception list, with a reason): ' . implode(', ', $uncapped)
        );
    }

    public function testAnOverLongValueIsRefusedPolitelyAndAValueAtTheLimitIsNot(): void
    {
        foreach (Caps::WIDTHS as $column => $cap) {
            [$table, $field] = explode('.', $column, 2);

            $exact = str_repeat('a', $cap);
            $over  = str_repeat('a', $cap + 1);

            $this->assertSame([], Caps::problems($table, [$field => $exact]), $column . ' at exactly its limit must be accepted');

            $problems = Caps::problems($table, [$field => $over]);
            $this->assertSame([$field], array_keys($problems), $column . ' one character over must be refused');

            $message = $problems[$field];
            $this->assertStringContains('too long', $message);
            $this->assertStringContains((string) $cap, $message, 'the message says what the limit is');
            $this->assertStringNotContains('aaaa', $message, 'and never quotes the value back');
        }
    }

    /* ===================================================================== */
    /*  TEXT COLUMNS AND ENCODING (audit round 1, finding 4)                  */
    /* ===================================================================== */

    /**
     * Every TEXT column in the schema has a byte budget, and every budget names a
     * real TEXT column. The same both-directions guard WIDTHS has, for the half
     * the class used to leave out: the docblock said TEXT was "capped by the
     * request caps in Request instead", and that cap counts characters while TEXT
     * holds bytes.
     */
    public function testEveryTextColumnHasAByteBudgetAndEveryBudgetIsATextColumn(): void
    {
        $schema = $this->schemaTextColumns();

        $this->assertTrue(count($schema) >= 5, 'the migration was parsed, not skipped: found ' . count($schema));

        $missing = [];
        foreach ($schema as $column) {
            if (!isset(Caps::TEXT_BYTES[$column])) {
                $missing[] = $column;
            }
        }
        $this->assertSame([], $missing, 'TEXT columns with no byte budget: ' . implode(', ', $missing));

        $notText = [];
        foreach (array_keys(Caps::TEXT_BYTES) as $column) {
            if (!in_array($column, $schema, true)) {
                $notText[] = $column;
            }
        }
        $this->assertSame([], $notText, 'named in TEXT_BYTES but not a TEXT column in the schema: ' . implode(', ', $notText));

        // Every budget is comfortably under the hard limit, with room that a
        // byte-counting mistake cannot fall off the end of.
        foreach (Caps::TEXT_BYTES as $column => $budget) {
            $this->assertTrue(
                $budget > 0 && $budget <= 60000,
                $column . ' budget is ' . $budget . ', which is not safely inside TEXT\'s 65,535 bytes'
            );
        }

        // And a column cannot be in both maps: the unit would be ambiguous.
        $this->assertSame(
            [],
            array_intersect(array_keys(Caps::WIDTHS), array_keys(Caps::TEXT_BYTES)),
            'a column is either characters or bytes, never both'
        );
    }

    /**
     * A TEXT column is counted in BYTES.
     *
     * The measured failure: 20,000 emoji is 80,000 bytes, which fits any
     * character-counting cap and does not fit a 65,535-byte column. Caps said
     * there was no problem and PDO disagreed.
     */
    public function testATextColumnIsCountedInBytesAndNotCharacters(): void
    {
        $budget = Caps::bytesOf('leads.message');

        // Right at the limit, in the worst case a person can produce: four bytes
        // per character.
        $exact = str_repeat('🚚', intdiv($budget, 4));
        $this->assertSame($budget, strlen($exact), 'the fixture really is the budget in bytes');
        $this->assertSame([], Caps::problems('leads', ['message' => $exact]), 'exactly the budget fits');

        $over = $exact . '🚚';
        $this->assertSame($budget + 4, strlen($over));
        $problems = Caps::problems('leads', ['message' => $over]);
        $this->assertSame(['message'], array_keys($problems), 'one character over the BYTE budget is refused');
        $this->assertStringContains('bytes', $problems['message'], 'and the message says bytes, because that is what was counted');
        $this->assertStringNotContains('🚚', $problems['message'], 'and never quotes the value back');

        // The character count of $over is a quarter of the byte count, which is
        // exactly why a character cap could never have caught this.
        $this->assertTrue(mb_strlen($over, 'UTF-8') < $budget, 'a character cap would have let it through');
    }

    /**
     * Invalid UTF-8 is refused with a sentence rather than an exception.
     *
     * `"Ali\xC3\x28 Khan"` - twelve bytes of `curl --data-binary` at the public
     * contact form - reached the database and came back as
     * `SQLSTATE[22007]: 1366 Incorrect string value`, i.e. a 500 page.
     */
    public function testInvalidUtf8IsRefusedPolitelyInEveryKindOfColumn(): void
    {
        $broken = "Ali\xC3\x28 Khan";
        $this->assertFalse(mb_check_encoding($broken, 'UTF-8'), 'the fixture really is malformed');

        // A VARCHAR column...
        $problems = Caps::problems('leads', ['name' => $broken]);
        $this->assertSame(['name'], array_keys($problems));
        $this->assertStringContains('could not read', $problems['name']);
        $this->assertStringContains('Name', $problems['name'], 'named the way the form names it');
        $this->assertStringNotContains('Ali', $problems['name'], 'and never quotes the value back');

        // ...and a TEXT one. Short, so length is not what refuses it.
        $textProblems = Caps::problems('leads', ['message' => $broken]);
        $this->assertSame(['message'], array_keys($textProblems));
        $this->assertStringContains('could not read', $textProblems['message']);

        // assert() carries it the same way it carries a length problem, so a
        // write path that uses the one line gets this for free.
        $error = $this->assertThrows(ValueTooLong::class, static fn () => Caps::assert('leads', ['name' => $broken]));
        /** @var ValueTooLong $error */
        $this->assertSame(['name'], array_keys($error->problems));

        // A lone truncated multi-byte sequence is the commonest real version of
        // this: a form cut at a byte boundary by something upstream.
        $this->assertSame(
            ['name'],
            array_keys(Caps::problems('leads', ['name' => "Kar\xE2\x82"])),
            'a cut-off UTF-8 sequence is the everyday case'
        );

        // And valid multi-byte text is not touched by any of it.
        $this->assertSame([], Caps::problems('leads', ['name' => 'کراچی', 'message' => 'سلام 🚚']));
    }

    /**
     * The database really does refuse what the guard now refuses, so the guard is
     * not protecting against an imagined failure.
     */
    public function testTheDatabaseItselfRefusesInvalidUtf8(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $broken = "Ali\xC3\x28 Khan";

        $this->assertThrows(\PDOException::class, static function () use ($broken): void {
            Db::insert('app_settings', [
                'k'          => 'caps-utf8-probe',
                'v'          => '"' . $broken . '"',
                'updated_at' => Clock::sql(),
                'updated_by' => $broken,
            ]);
        }, 'utf8mb4 plus STRICT_TRANS_TABLES makes a malformed byte an error');

        $this->assertSame(
            ['updated_by'],
            array_keys(Caps::problems('app_settings', ['updated_by' => $broken])),
            'and the validator catches it first, so no route has to'
        );

        Db::exec('DELETE FROM app_settings WHERE k = ?', ['caps-utf8-probe']);
    }

    public function testTheMessageIsWrittenForThePersonReadingIt(): void
    {
        $problems = Caps::problems('leads', ['phone' => str_repeat('9', 41)]);

        $this->assertSame(
            'Phone number is too long: 40 characters at most, and that is 41.',
            $problems['phone'] ?? ''
        );

        $this->assertSame(
            'Phone number is too long: 40 characters at most, and that is 41.',
            Caps::firstProblem('leads', ['phone' => str_repeat('9', 41)])
        );
        $this->assertNull(Caps::firstProblem('leads', ['phone' => '03001234567']));
    }

    public function testLengthIsCountedInCharactersTheWayTheDatabaseCountsIt(): void
    {
        // VARCHAR(190) on a utf8mb4 column holds 190 CHARACTERS. Counting
        // bytes would refuse a perfectly good Urdu name at half the length,
        // and an emoji-carrying one at a quarter.
        $urdu = str_repeat('ا', 190);

        $this->assertSame(380, strlen($urdu), 'two bytes each in UTF-8');
        $this->assertSame([], Caps::problems('leads', ['name' => $urdu]), '190 characters fit');
        $this->assertSame(['name'], array_keys(Caps::problems('leads', ['name' => $urdu . 'ا'])));

        $emoji = str_repeat('🚚', 190);
        $this->assertSame(760, strlen($emoji), 'four bytes each');
        $this->assertSame([], Caps::problems('leads', ['name' => $emoji]));
    }

    public function testValuesThatAreNotStringsAreNotLengthChecked(): void
    {
        $this->assertSame([], Caps::problems('leads', ['weight_g' => 5000, 'status' => null]));
        $this->assertSame([], Caps::problems('leads', ['not_a_column' => str_repeat('x', 9000)]), 'unknown columns are not this class\'s business');
    }

    public function testAnUnknownColumnThrowsRatherThanQuietlyMeaningUnlimited(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Caps::of('leads.nope'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Caps::fit('leads.nope', 'x'));
    }

    /**
     * The one-line guard a write path is supposed to use.
     *
     * problems() was called from nowhere in the application for the whole time
     * this class existed, so every over-long value still became a PDOException.
     * assert() is the version that is hard to forget to check, and these are the
     * properties a repository depends on: silence when the row fits, a named
     * type when it does not, and the per-field sentences carried along so the
     * route above does not have to run the check twice.
     */
    public function testAssertIsSilentWhenTheRowFits(): void
    {
        Caps::assert('leads', [
            'name'    => 'Zulqarnain Haider',
            'phone'   => '03001234567',
            'email'   => 'boss@speedat.test',
            'dest_id' => 'united-kingdom',
            // Not a capped column, and not this class's business.
            'message' => str_repeat('x', 3000),
            // Not a string, so not length-checked.
            'weight_g' => 5000,
        ]);

        // And an empty row is not an error - a repository may build the array
        // conditionally.
        Caps::assert('leads', []);

        $this->assertTrue(true, 'nothing was thrown, which is the whole assertion');
    }

    public function testAssertThrowsATypeTheCallerCanNameAndCarriesEveryProblem(): void
    {
        $error = $this->assertThrows(ValueTooLong::class, static fn () => Caps::assert('leads', [
            'name'  => str_repeat('n', 191),
            'phone' => str_repeat('9', 41),
            'email' => 'boss@speedat.test',
        ]));

        /** @var ValueTooLong $error */
        $this->assertSame('leads', $error->table);
        $this->assertCount(2, $error->problems, 'both over-long fields, not just the first');
        $this->assertSame(['name', 'phone'], array_keys($error->problems));

        // The message is the sentence a customer could be shown, and it never
        // quotes the value back - this ends up in a log.
        $this->assertStringContains('too long', $error->getMessage());
        $this->assertStringNotContains('nnnn', $error->getMessage());
        $this->assertStringNotContains('9999', $error->getMessage());

        // columns() is for the log line: which fields, never what was in them.
        $this->assertSame('name, phone', $error->columns());

        // It is a \LengthException as well, so a caller that catches the SPL
        // type rather than ours still catches it.
        $this->assertTrue($error instanceof \LengthException);
    }

    /**
     * No repository may carry a length constant LOOSER than the column it
     * guards.
     *
     * This is the direction that breaks the site. A local `MAX_CARRIER = 100`
     * against `shipments.carrier VARCHAR(80)` passes its own validation, reaches
     * Db::insert(), and becomes a 500 page - which is the exact failure Caps was
     * written to end, reintroduced one file away from it.
     *
     * Stricter than the column is allowed: the contact form may well want a
     * shorter name field than the database can hold. Those are listed in the
     * failure message when something else fails, so the reader sees the whole
     * picture at once, and the right end state is that they read
     * Caps::of('table.column') instead of carrying a number.
     */
    public function testNoLocalConstantIsLooserThanTheColumnItGuards(): void
    {
        $widths = $this->narrowestCapPerColumnName();

        // A constant whose NAME happens to match a column but which measures
        // something else entirely. Add one only with the reason, because every
        // entry here is a column nobody is watching any more.
        $unrelated = [];

        $looser   = [];
        $stricter = [];

        foreach ($this->maxConstants() as $found) {
            $column = strtolower($found['name']);

            if (!isset($widths[$column]) || isset($unrelated[$found['where'] . '::MAX_' . $found['name']])) {
                continue;
            }

            $cap  = $widths[$column];
            $line = $found['where'] . ':' . $found['line'] . ' MAX_' . $found['name'] . ' = ' . $found['value']
                . ' against ' . $column . ' VARCHAR(' . $cap . ')';

            if ($found['value'] > $cap) {
                $looser[] = $line;
            } elseif ($found['value'] < $cap) {
                $stricter[] = $line;
            }
        }

        $this->assertTrue(count($stricter) + count($looser) > 0, 'the app tree was scanned, not skipped');

        $this->assertSame(
            [],
            $looser,
            'these accept more than the column holds, so they end in a PDOException: '
            . implode('; ', $looser)
            . ' -- use Caps::of(...). Deliberately stricter, for reference: '
            . (implode('; ', $stricter) ?: 'none')
        );
    }

    public function testFitCutsMachineValuesToTheColumn(): void
    {
        $this->assertSame(190, mb_strlen(Caps::fit('audit_log.actor', str_repeat('a', 400))));
        $this->assertSame('short', Caps::fit('audit_log.actor', 'short'));
        $this->assertSame('', Caps::fit('audit_log.actor', null));
    }

    /**
     * The failure this all exists to prevent, demonstrated: an over-long value
     * that reaches the database is an exception, not a truncation.
     *
     * If this test ever starts failing, STRICT_TRANS_TABLES has been lost from
     * the connection and over-long values are being silently cut - which is
     * worse than the 500, because the data is wrong and nobody is told.
     */
    public function testTheDatabaseItselfRefusesAnOverLongValue(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $tooLong = str_repeat('k', Caps::of('app_settings.k') + 1);

        $this->assertThrows(\PDOException::class, static function () use ($tooLong): void {
            Db::insert('app_settings', [
                'k'          => $tooLong,
                'v'          => Db::json([]),
                'updated_at' => Clock::sql(),
                'updated_by' => 'caps-test',
            ]);
        }, 'strict mode turns an over-long value into an error');

        // And the validator catches it first, so no route has to.
        $this->assertSame(['k'], array_keys(Caps::problems('app_settings', ['k' => $tooLong])));

        Db::exec('DELETE FROM app_settings WHERE updated_by = ?', ['caps-test']);
    }

    /**
     * `column name => the smallest cap any table gives that name`.
     *
     * Keyed by the bare column name because a local constant is called
     * MAX_DEST_ID, not MAX_LEADS_DEST_ID, and the same name means the same kind
     * of value in every table it appears in. The SMALLEST width is the safe one
     * to measure against: a constant that fits every table's version of the
     * column cannot overflow any of them.
     *
     * @return array<string,int>
     */
    private function narrowestCapPerColumnName(): array
    {
        $widths = [];

        foreach (Caps::WIDTHS as $key => $cap) {
            $column = substr($key, (int) strpos($key, '.') + 1);

            $widths[$column] = isset($widths[$column]) ? min($widths[$column], $cap) : $cap;
        }

        return $widths;
    }

    /**
     * Every `const MAX_SOMETHING = <int>` under app/, as source text.
     *
     * Reading the files rather than reflecting over loaded classes on purpose:
     * reflection would only see the classes this test run happens to have
     * autoloaded, so a repository nobody touched would be silently exempt - and
     * a guard that skips the files nobody is looking at is no guard.
     *
     * @return list<array{where:string,line:int,name:string,value:int}>
     */
    private function maxConstants(): array
    {
        $found = [];
        $root  = Paths::root() . '/app';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $entry */
        foreach ($files as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            // The rate-sheet import is removed from the product and its files
            // are waiting to be deleted; they are not held to anything.
            $where = str_replace('\\', '/', substr($entry->getPathname(), strlen(Paths::root()) + 1));
            if (str_starts_with($where, 'app/Domain/Import/')) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($entry->getPathname())) as $i => $line) {
                if (preg_match('/\bconst\s+MAX_([A-Z0-9_]+)\s*=\s*([0-9_]+)\s*;/', $line, $m) !== 1) {
                    continue;
                }

                $found[] = [
                    'where' => $where,
                    'line'  => $i + 1,
                    'name'  => $m[1],
                    'value' => (int) str_replace('_', '', $m[2]),
                ];
            }
        }

        return $found;
    }

    /**
     * `table.column => width` for every VARCHAR in db/migrations/*.sql.
     *
     * Deliberately a small parser over the real file rather than a query
     * against information_schema: the file is what a new host imports, and a
     * database that has drifted from it is a separate problem from a cap that
     * has drifted from the file.
     *
     * @return array<string,int>
     */
    private function schemaWidths(): array
    {
        return $this->schemaColumns()['varchar'];
    }

    /** @return list<string> `table.column` for every TEXT column in the schema */
    private function schemaTextColumns(): array
    {
        return array_keys($this->schemaColumns()['text']);
    }

    /**
     * The schema's columns, split by the unit their limit is measured in.
     *
     * TEXT is read as well as VARCHAR because the two fail in exactly the same
     * way - STRICT_TRANS_TABLES, an exception, a 500 - and the class only used to
     * mirror one of them (audit round 1, finding 4).
     *
     * @return array{varchar:array<string,int>,text:array<string,int>}
     */
    private function schemaColumns(): array
    {
        $varchar = [];
        $text    = [];

        foreach (glob(Paths::migrations() . '/*.sql') ?: [] as $file) {
            $sql = (string) file_get_contents($file);

            // Comments first: the migrations explain themselves at length, and
            // "VARCHAR(190), not 255" in a comment is not a column - nor is
            // "TEXT has no DEFAULT", which is a real line in 0001_init.sql.
            $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

            $table = '';
            foreach (explode("\n", $sql) as $line) {
                if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $line, $m) === 1) {
                    $table = strtolower($m[1]);
                    continue;
                }
                if ($table === '') {
                    continue;
                }
                if (preg_match('/^\s*`?([A-Za-z0-9_]+)`?\s+VARCHAR\((\d+)\)/i', $line, $m) === 1) {
                    $varchar[$table . '.' . strtolower($m[1])] = (int) $m[2];
                    continue;
                }
                // TEXT, but not MEDIUMTEXT/LONGTEXT/TINYTEXT, which have their
                // own limits and none of which this schema uses.
                if (preg_match('/^\s*`?([A-Za-z0-9_]+)`?\s+TEXT\b/i', $line, $m) === 1) {
                    $text[$table . '.' . strtolower($m[1])] = 65535;
                }
            }
        }

        return ['varchar' => $varchar, 'text' => $text];
    }
}
