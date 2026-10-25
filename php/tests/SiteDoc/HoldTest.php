<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\HoldState;
use Speedat\Domain\SiteDoc\Text;

/**
 * The price hold: message hygiene (pure) and the store (against MariaDB).
 * Same cases as src/lib/site/hold.test.ts.
 */
final class HoldTest extends SiteDocTestCase
{
    public function setUp(): void
    {
        $this->useScratchCache();
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [Hold::KEY]);
        }

        $this->dropScratchCache();
    }

    public function testTheMessageFallsBackToTheDefaultAndIsCapped(): void
    {
        $this->assertSame(HoldState::DEFAULT_MESSAGE, HoldState::cleanMessage(''));
        $this->assertSame(HoldState::DEFAULT_MESSAGE, HoldState::cleanMessage(null));
        $this->assertSame(HoldState::DEFAULT_MESSAGE, HoldState::cleanMessage('   '));
        $this->assertSame('New rates soon', HoldState::cleanMessage("  New   rates\n\nsoon  "));
        $this->assertSame(
            HoldState::MESSAGE_MAX,
            mb_strlen(HoldState::cleanMessage(str_repeat('x', HoldState::MESSAGE_MAX + 50)), 'UTF-8')
        );
        $this->assertSame('42', HoldState::cleanMessage(42));
    }

    public function testACapIsCountedInCharactersSoNoMultibyteCharacterIsCutInHalf(): void
    {
        $long  = str_repeat("\u{00e9}", HoldState::MESSAGE_MAX + 10);
        $clean = HoldState::cleanMessage($long);

        $this->assertSame(HoldState::MESSAGE_MAX, mb_strlen($clean, 'UTF-8'));
        $this->assertSame($clean, mb_convert_encoding($clean, 'UTF-8', 'UTF-8'), 'the cut left valid UTF-8');
    }

    /**
     * The hold message is capped by a plain JavaScript `slice(0, 300)`, and
     * JavaScript counts UTF-16 CODE UNITS - so an emoji costs two, not one.
     *
     * This is NOT the rule the schema's own caps follow (zod 4 counts code
     * points), and getting it wrong is not academic: `mb_substr($s, 0, 300)`
     * keeps 200 emoji where the Next.js admin keeps 150, so the owner would
     * preview one sentence and the public page would print a longer one.
     */
    public function testTheCapCountsUtf16CodeUnitsTheWayJavascriptsSliceDoes(): void
    {
        $smile = "\u{1F642}";

        // 200 emoji is 400 code units; the first 300 of them are 150 emoji.
        $this->assertSame(
            str_repeat($smile, 150),
            HoldState::cleanMessage(str_repeat($smile, 200))
        );

        // Exactly 300 code units is kept whole...
        $this->assertSame(
            str_repeat($smile, 150),
            HoldState::cleanMessage(str_repeat($smile, 150))
        );

        // ...and one more emoji is 302, so one is dropped.
        $this->assertSame(
            str_repeat($smile, 150),
            HoldState::cleanMessage(str_repeat($smile, 151))
        );

        // A plain BMP string is unaffected: one character, one code unit, so the
        // cut is simply at the 300th character.
        $this->assertSame(
            str_repeat("\u{00e9}", 299) . 'Z',
            HoldState::cleanMessage(str_repeat("\u{00e9}", 299) . 'ZZZ')
        );
    }

    /**
     * Where the cut would land between the two halves of a surrogate pair,
     * JavaScript keeps the dangling half and the result is not well-formed text.
     * We stop one code unit earlier instead.
     *
     * A lone surrogate cannot be stored in a utf8mb4 column and cannot be
     * rendered, so being bug-compatible here would trade a valid sentence for an
     * insert that fails. The message is at most one character shorter than
     * JavaScript's, and always valid UTF-8.
     */
    public function testACutThatWouldSplitACharacterStopsOneUnitEarlier(): void
    {
        $smile = "\u{1F642}";

        // "x" is one code unit, so the 300th unit falls inside the 150th emoji.
        $clean = HoldState::cleanMessage('x' . str_repeat($smile, 200));

        $this->assertSame('x' . str_repeat($smile, 149), $clean);
        $this->assertSame($clean, mb_convert_encoding($clean, 'UTF-8', 'UTF-8'), 'the cut left valid UTF-8');
        $this->assertSame(299, Text::lengthUtf16($clean), 'one unit short of the cap, never over it');
    }

    /**
     * JavaScript's idea of whitespace, which is wider than PHP's: the
     * non-breaking space that arrives in every value pasted out of Word, the line
     * and paragraph separators, the ideographic space, and the byte-order mark
     * that even PHP's Unicode-aware `\s` does not match.
     */
    public function testJavascriptsWholeWhitespaceSetIsCollapsed(): void
    {
        $this->assertSame('a b', HoldState::cleanMessage("a\u{FEFF}b"));
        $this->assertSame('a b c', HoldState::cleanMessage("a\u{2028}b\u{2029}c"));
        $this->assertSame('a b c d', HoldState::cleanMessage("a\u{3000}b\u{2000}c\u{205F}d"));
        $this->assertSame('a b c', HoldState::cleanMessage("a\u{1680}b\u{202F}c"));
        $this->assertSame('tabbed', HoldState::cleanMessage("\u{000B}\u{000C} tabbed \u{000B}"));
        $this->assertSame('padded', HoldState::cleanMessage("\u{00A0}padded\u{00A0}"));

        // A message that is nothing but whitespace is no message at all.
        $this->assertSame(HoldState::DEFAULT_MESSAGE, HoldState::cleanMessage("\u{FEFF}"));
        $this->assertSame(HoldState::DEFAULT_MESSAGE, HoldState::cleanMessage("\u{3000}\u{00A0}"));
    }

    public function testItIsOffUntilSwitchedThenRemembersWhoAndWhen(): void
    {
        $this->needDatabase();
        Db::exec('DELETE FROM app_settings WHERE k = ?', [Hold::KEY]);

        $before = Hold::get();
        $this->assertFalse($before->on);
        $this->assertSame(HoldState::DEFAULT_MESSAGE, $before->message);
        $this->assertSame('', $before->by);
        $this->assertNull($before->since);

        $on = Hold::set(true, 'owner@x.com', 'Back in an hour');
        $this->assertTrue($on->on);
        $this->assertSame('Back in an hour', $on->message);
        $this->assertSame('owner@x.com', $on->by);
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $on->since));

        $read = Hold::get();
        $this->assertSame($on->toArray(), $read->toArray());
    }

    public function testSwitchingOffWithoutAMessageKeepsTheOneTheOwnerWrote(): void
    {
        $this->needDatabase();
        Db::exec('DELETE FROM app_settings WHERE k = ?', [Hold::KEY]);

        Hold::set(true, 'owner@x.com', 'Back in an hour');
        $off = Hold::set(false, 'owner@x.com');

        $this->assertFalse($off->on);
        $this->assertSame('Back in an hour', $off->message, 'the next hold starts from the same sentence');
    }

    public function testJunkInTheRowReadsAsOffRatherThanThrowing(): void
    {
        $this->needDatabase();

        Db::exec(
            'INSERT INTO app_settings (k, v, updated_by) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE v = ?, updated_by = ?',
            [Hold::KEY, '{"on": "yes", "message": 7, "by": null}', 'test', '{"on": "yes", "message": 7, "by": null}', 'test']
        );

        $hold = Hold::get();

        $this->assertFalse($hold->on, '"yes" is not true; a damaged row must not take prices off the site');
        $this->assertSame('7', $hold->message);
        $this->assertSame('', $hold->by);
        $this->assertNull($hold->since);
    }

    public function testAnEmptyRowReadsAsTheUntouchedDefault(): void
    {
        $this->needDatabase();

        Db::exec(
            'INSERT INTO app_settings (k, v, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = ?',
            [Hold::KEY, '{}', 'test', '{}']
        );

        $this->assertSame(HoldState::none()->toArray(), Hold::get()->toArray());
    }
}
