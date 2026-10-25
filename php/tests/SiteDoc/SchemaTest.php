<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\Content;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\Seed;

/**
 * The shape-and-size check, which is the last thing between a pasted Word
 * document and MySQL's STRICT_TRANS_TABLES.
 *
 * Its job is not to be clever. Its job is to name the field, so the owner reads
 * "that FAQ is too long" instead of "Something went wrong" and keeps his edit.
 *
 * EVERY MESSAGE HERE WAS TAKEN FROM THE INSTALLED ZOD BY RUNNING IT, not from
 * memory. The same document is validated by the Next.js app against zod 4.6.5,
 * and the two halves of one product must not disagree about why an edit was
 * refused. zod 4 rewrote every one of these strings - it says "Too big: expected
 * string to have <=80 characters" where zod 3 said "String must contain at most
 * 80 character(s)" - so an assertion copied from a zod 3 memory would pin the
 * port to wording the owner never sees. tests/SiteDoc/parity/ holds the harness
 * that generated them.
 */
final class SchemaTest extends SiteDocTestCase
{
    public function testTheSeedIsAccepted(): void
    {
        $this->assertTrue(Schema::accepts(Seed::site()->toArray()));
        $this->assertTrue(Schema::accepts(Seed::gridSite()->toArray()));
        $this->assertNull(Schema::firstIssue(Seed::data()));
    }

    public function testALongValueIsAPoliteRejectionNamingTheField(): void
    {
        $doc                      = Seed::site()->toArray();
        $doc['content']['phone2'] = str_repeat('x', 81);

        $issue = Schema::firstIssue($doc);

        $this->assertNotNull($issue);
        $this->assertSame('content.phone2', $issue?->path);
        $this->assertSame('Too big: expected string to have <=80 characters', $issue?->message);
        $this->assertSame(
            'content.phone2: Too big: expected string to have <=80 characters',
            (string) $issue
        );

        // Exactly at the cap is fine; the cap is inclusive on both sides.
        $doc['content']['phone2'] = str_repeat('x', 80);
        $this->assertTrue(Schema::accepts($doc));
    }

    public function testCapsAreCountedInCharactersNotBytes(): void
    {
        $doc = Seed::site()->toArray();

        // 120 three-byte characters: 360 bytes, but 120 characters, and "hours"
        // takes 120. Counting bytes here would refuse a perfectly legal line.
        $doc['content']['hours'] = str_repeat("\u{4e00}", 120);
        $this->assertTrue(Schema::accepts($doc));

        $doc['content']['hours'] = str_repeat("\u{4e00}", 121);
        $this->assertSame('content.hours', Schema::firstIssue($doc)?->path);
    }

    /**
     * zod 4 measures a string in CODE POINTS, not UTF-16 code units: it counts
     * with `[...s].length`, so one emoji is one character and not two.
     *
     * 61 emoji is 122 UTF-16 code units and would be refused by a cap of 120 if
     * this counted the way JavaScript's own `.length` does. It must not.
     */
    public function testCapsAreCountedInCodePointsTheWayZodCounts(): void
    {
        $doc = Seed::site()->toArray();

        $doc['content']['routesTitle'] = str_repeat("\u{1F642}", 61);
        $this->assertTrue(Schema::accepts($doc), '61 emoji is 61 characters, under the cap of 120');

        $doc['content']['routesTitle'] = str_repeat("\u{1F642}", 121);
        $this->assertSame(
            'Too big: expected string to have <=120 characters',
            Schema::firstIssue($doc)?->message
        );
    }

    public function testEveryContentFieldHasACap(): void
    {
        foreach (Content::FIELDS as $field) {
            $doc                    = Seed::site()->toArray();
            $doc['content'][$field] = str_repeat('x', 12001);

            $issue = Schema::firstIssue($doc);
            $this->assertSame('content.' . $field, $issue?->path, $field . ' is not capped');
        }
    }

    /**
     * A field that is not there at all must be refused, and refused as
     * "undefined" rather than as some stand-in value.
     *
     * This is a regression test for a real bug in this port: the absence of a key
     * was represented by a magic STRING, which `is_string()` happily accepted, so
     * a document missing a whole text field was declared valid and published. The
     * sentinel is an object now, of a type no field may hold.
     */
    public function testAMissingFieldIsRefusedAndNamedUndefined(): void
    {
        $doc = Seed::site()->toArray();
        unset($doc['content']['phone2']);
        $this->assertSame(
            'content.phone2: Invalid input: expected string, received undefined',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::site()->toArray();
        unset($doc['company']['name']);
        $this->assertSame(
            'company.name: Invalid input: expected string, received undefined',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::site()->toArray();
        unset($doc['settings']['showEta']);
        $this->assertSame(
            'settings.showEta: Invalid input: expected boolean, received undefined',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::site()->toArray();
        unset($doc['settings']['roundTo']);
        $this->assertSame(
            'settings.roundTo: Invalid input: expected number, received undefined',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::site()->toArray();
        unset($doc['destinations']);
        $this->assertSame(
            'destinations: Invalid input: expected array, received undefined',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::site()->toArray();
        unset($doc['content']);
        $this->assertSame(
            'content: Invalid input: expected object, received undefined',
            (string) Schema::firstIssue($doc)
        );

        // Every one of them, and nothing else, on an entirely empty document.
        $paths = [];
        foreach (Schema::check([]) as $issue) {
            $paths[] = $issue->path;
        }
        $this->assertSame(
            ['live', 'company', 'settings', 'services', 'destinations', 'content', 'importProfiles'],
            $paths
        );
    }

    /**
     * "null" and "absent" are different answers and get different sentences. The
     * owner needs to know which, because one of them is a blank he typed and the
     * other is a field the form failed to send.
     */
    public function testNullIsNotTheSameComplaintAsAbsent(): void
    {
        $doc                 = Seed::site()->toArray();
        $doc['content']['hours'] = null;
        $this->assertSame(
            'content.hours: Invalid input: expected string, received null',
            (string) Schema::firstIssue($doc)
        );
    }

    /** zod names a `z.record` a "record" when it refuses one, not an "object". */
    public function testARecordIsNamedARecord(): void
    {
        $doc                            = Seed::site()->toArray();
        $doc['destinations'][0]['rates'] = null;
        $this->assertSame(
            'destinations.0.rates: Invalid input: expected record, received null',
            (string) Schema::firstIssue($doc)
        );

        $doc = Seed::gridSite()->toArray();
        $doc['destinations'][0]['rates']['express']['grid'] = null;
        $this->assertSame(
            'destinations.0.rates.express.grid: Invalid input: expected record, received null',
            (string) Schema::firstIssue($doc)
        );

        // ...but a plain `z.object` is still an object.
        $doc            = Seed::site()->toArray();
        $doc['settings'] = null;
        $this->assertSame(
            'settings: Invalid input: expected object, received null',
            (string) Schema::firstIssue($doc)
        );
    }

    public function testTheGridKeyPatternIsEnforced(): void
    {
        $doc = Seed::gridSite()->toArray();
        $doc['destinations'][0]['rates']['express']['grid']['2.5'] = 100;

        $issue = Schema::firstIssue($doc);
        $this->assertSame('destinations.0.rates.express.grid.2.5', $issue?->path);
        $this->assertSame('Invalid key in record', $issue?->message);

        $doc = Seed::gridSite()->toArray();
        $doc['destinations'][0]['rates']['express']['grid']['1000'] = 100;
        $this->assertSame(
            'Invalid key in record',
            Schema::firstIssue($doc)?->message,
            'four digits is beyond the pattern'
        );
    }

    /**
     * A record entry whose KEY is refused has its value left unread - one
     * complaint per entry, not two. A "2.5 kg" box the engine could never find is
     * one mistake, and telling the owner about its price as well would send him
     * looking for a second problem that is not there.
     */
    public function testARefusedKeyLeavesItsValueUnread(): void
    {
        $doc = Seed::gridSite()->toArray();
        $doc['destinations'][0]['rates']['express']['grid'] = ['2.5' => 'free'];

        $issues = Schema::check($doc);

        $this->assertCount(1, $issues, 'one entry, one complaint');
        $this->assertSame('Invalid key in record', $issues[0]->message);
    }

    /**
     * A record's problems are listed in the order JAVASCRIPT would walk its keys,
     * because the Next.js app lists the same document's problems and the owner
     * may see either. JavaScript hoists canonical integer keys to the front in
     * ascending order and leaves the rest in insertion order; PHP keeps
     * everything in insertion order, so the port has to re-sort.
     */
    public function testRecordProblemsAreListedInJavascriptsKeyOrder(): void
    {
        $doc = Seed::gridSite()->toArray();
        // Written 5, 1, "2.5": JavaScript would walk 1, 5, "2.5".
        $doc['destinations'][0]['rates']['express']['grid'] = ['5' => 'x', '1' => 'y', '2.5' => 1];

        $paths = [];
        foreach (Schema::check($doc) as $issue) {
            $paths[] = $issue->path;
        }

        $this->assertSame(
            [
                'destinations.0.rates.express.grid.1',
                'destinations.0.rates.express.grid.5',
                'destinations.0.rates.express.grid.2.5',
            ],
            $paths
        );
    }

    public function testPricesMustBeNumbersInRange(): void
    {
        $doc                                                = Seed::site()->toArray();
        $doc['destinations'][0]['rates']['express']['first'] = -1;
        $this->assertSame(
            'Too small: expected number to be >=0',
            Schema::firstIssue($doc)?->message
        );

        $doc['destinations'][0]['rates']['express']['first'] = 100000001;
        $this->assertSame(
            'Too big: expected number to be <=100000000',
            Schema::firstIssue($doc)?->message
        );

        $doc['destinations'][0]['rates']['express']['first'] = 100000000;
        $this->assertTrue(Schema::accepts($doc), 'the ceiling is inclusive');

        $doc['destinations'][0]['rates']['express']['first'] = 'free';
        $this->assertSame(
            'Invalid input: expected number, received string',
            Schema::firstIssue($doc)?->message
        );

        // A blank price is "not offered", which is legal here - whether it leaves
        // the destination unsellable is `Validate`'s question, not this one's.
        $doc['destinations'][0]['rates']['express']['first'] = null;
        $this->assertTrue(Schema::accepts($doc));

        unset($doc['destinations'][0]['rates']['express']['first']);
        $this->assertTrue(Schema::accepts($doc), 'an absent price is the same as a null one');
    }

    /**
     * `RateSchema.optional()` means the service may be ABSENT from the rates, not
     * that it may be null. A null rate is a half-written document, and the site
     * would read it as "this service has no prices" without anybody being told.
     */
    public function testAnAbsentRateIsFineButANullOneIsNot(): void
    {
        $doc = Seed::site()->toArray();
        unset($doc['destinations'][0]['rates']['normal']);
        $this->assertTrue(Schema::accepts($doc));

        $doc['destinations'][0]['rates']['normal'] = null;
        $this->assertSame(
            'destinations.0.rates.normal: Invalid input: expected object, received null',
            (string) Schema::firstIssue($doc)
        );
    }

    public function testIdsMustBeSlugs(): void
    {
        $doc                          = Seed::site()->toArray();
        $doc['destinations'][0]['id'] = 'United Kingdom';

        $issue = Schema::firstIssue($doc);
        $this->assertSame('destinations.0.id', $issue?->path);
        $this->assertSame('Invalid string: must match pattern /^[a-z0-9-]+$/', $issue?->message);

        $doc                     = Seed::site()->toArray();
        $doc['services'][0]['id'] = 'Express Air';
        $this->assertSame(
            'services.0.id: Invalid string: must match pattern /^[a-z0-9-]+$/',
            (string) Schema::firstIssue($doc)
        );
    }

    public function testThePricingModeIsAnEnum(): void
    {
        $doc                            = Seed::site()->toArray();
        $doc['settings']['pricingMode'] = 'per-kilo';

        $this->assertSame(
            'Invalid option: expected one of "slab"|"grid"',
            Schema::firstIssue($doc)?->message
        );

        // An enum says the same thing whether the value is wrong or missing: it
        // never names what it received.
        unset($doc['settings']['pricingMode']);
        $this->assertSame(
            'settings.pricingMode: Invalid option: expected one of "slab"|"grid"',
            (string) Schema::firstIssue($doc)
        );
    }

    public function testACutoffMayBeNullButNotAbsent(): void
    {
        $doc                           = Seed::site()->toArray();
        $doc['settings']['cutoffHour'] = null;
        $this->assertTrue(Schema::accepts($doc));

        unset($doc['settings']['cutoffHour']);
        $this->assertSame(
            'Invalid input: expected number, received undefined',
            Schema::firstIssue($doc)?->message
        );
    }

    public function testCollectionsHaveCeilings(): void
    {
        $doc = Seed::site()->toArray();

        $doc['services'] = array_fill(0, 7, ['id' => 'x', 'name' => 'X', 'note' => '']);
        $this->assertSame(
            'Too big: expected array to have <=6 items',
            Schema::firstIssue($doc)?->message
        );

        $doc                 = Seed::site()->toArray();
        $one                 = $doc['destinations'][0];
        $doc['destinations'] = array_fill(0, 501, $one);
        $this->assertSame(
            'Too big: expected array to have <=500 items',
            Schema::firstIssue($doc)?->message
        );

        $doc                   = Seed::site()->toArray();
        $doc['importProfiles'] = array_fill(0, 21, [
            'signature' => 's', 'name' => 'n', 'map' => [], 'savedAt' => '',
            'cost' => false, 'margin' => 0, 'mround' => 0,
        ]);
        $this->assertSame(
            'Too big: expected array to have <=20 items',
            Schema::firstIssue($doc)?->message
        );
    }

    /**
     * An array reports what is wrong INSIDE it before it reports that there is
     * too much of it, which is the order zod builds the two.
     */
    public function testTheArraysCeilingIsReportedAfterItsElements(): void
    {
        $doc             = Seed::site()->toArray();
        $doc['services'] = array_fill(0, 7, ['id' => 'x', 'name' => 'X', 'note' => '']);
        $doc['services'][0]['id'] = 'NOT A SLUG';

        $messages = [];
        foreach (Schema::check($doc) as $issue) {
            $messages[] = (string) $issue;
        }

        $this->assertSame(
            [
                'services.0.id: Invalid string: must match pattern /^[a-z0-9-]+$/',
                'services: Too big: expected array to have <=6 items',
            ],
            $messages
        );
    }

    /** An import layout maps a field to a column NUMBER, and a half column is not one. */
    public function testAMappedColumnMustBeAWholeNumber(): void
    {
        $doc                   = Seed::site()->toArray();
        $doc['importProfiles'] = [[
            'signature' => 's', 'name' => 'n', 'map' => ['first' => 1.5], 'savedAt' => '',
            'cost' => false, 'margin' => 0, 'mround' => 0,
        ]];
        $this->assertSame(
            'importProfiles.0.map.first: Invalid input: expected int, received number',
            (string) Schema::firstIssue($doc)
        );

        $doc['importProfiles'][0]['map'] = ['first' => 'column B'];
        $this->assertSame(
            'importProfiles.0.map.first: Invalid input: expected number, received string',
            (string) Schema::firstIssue($doc)
        );

        // -1 is how a layout says "this field is not in the file at all".
        $doc['importProfiles'][0]['map'] = ['first' => -1];
        $this->assertTrue(Schema::accepts($doc));
    }

    public function testEveryIssueIsReportedNotJustTheFirst(): void
    {
        $doc                       = Seed::site()->toArray();
        $doc['content']['phone2']  = str_repeat('x', 81);
        $doc['content']['hours']   = str_repeat('x', 121);
        $doc['company']['tagline'] = str_repeat('x', 121);

        $paths = [];
        foreach (Schema::check($doc) as $issue) {
            $paths[] = $issue->path;
        }

        $this->assertSame(['company.tagline', 'content.hours', 'content.phone2'], $paths);
    }

    /**
     * A too-long value that ALSO breaks its pattern earns both complaints, in
     * that order - the size check runs before the pattern check.
     */
    public function testALongIdIsBothTooLongAndNotASlug(): void
    {
        $doc                     = Seed::site()->toArray();
        $doc['services'][0]['id'] = str_repeat('A', 41);

        $messages = [];
        foreach (Schema::check($doc) as $issue) {
            $messages[] = $issue->message;
        }

        $this->assertSame(
            [
                'Too big: expected string to have <=40 characters',
                'Invalid string: must match pattern /^[a-z0-9-]+$/',
            ],
            $messages
        );
    }
}
