<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Str;
use Speedat\Tests\TestCase;

/**
 * Str::squish(), the last thing a one-line text field passes through before it
 * is stored.
 *
 * The case these tests exist for: a NUL byte survived every layer of input
 * validation and was written into `leads.name`. Request::clean() repairs
 * malformed UTF-8 but says nothing about control characters, Caps checks
 * lengths and encoding, and squish() collapsed `\s` - which in PCRE is tab,
 * LF, CR, FF and VT, and nothing else below U+0020. NUL, ESC and the rest of
 * the C0 block went straight through.
 *
 * A stored NUL is not cosmetic. It is one string to MySQL, a different one in a
 * CSV export the owner opens in Excel, and a truncated one to anything that
 * still treats NUL as the end of a C string - so "Ali\0 Khan" and "Ali" can be
 * the same customer in one place and two in another.
 */
final class StrTest extends TestCase
{
    public function testANulByteDoesNotSurviveSquish(): void
    {
        $this->assertSame('AliKhan', Str::squish("Ali\x00Khan"), 'a NUL between two letters is dropped, not stored');
        $this->assertSame('Ali Khan', Str::squish("Ali Khan\x00"), 'a trailing NUL leaves no trailing byte behind');
        $this->assertSame('', Str::squish("\x00\x00"), 'a field of nothing but NULs is empty, so the empty-field rule can refuse it');
    }

    /**
     * A vertical tab IS whitespace to PCRE, so it was already collapsed. The
     * test pins that, because "it happens to work" is how a regex change puts
     * a raw 0x0B back into a name without anybody noticing.
     */
    public function testAVerticalTabBecomesASingleSpaceRatherThanARawByte(): void
    {
        $this->assertSame('Ali Khan', Str::squish("Ali\x0BKhan"));
        $this->assertSame('Ali Khan', Str::squish("Ali \x0B\t\nKhan"), 'a run of mixed whitespace is one space');
    }

    public function testAStrayEscapeIsRemoved(): void
    {
        // \x1B[31m is what a terminal colour code looks like when it arrives in
        // a form field: harmless in HTML, dangerous in a log file anyone tails.
        $this->assertSame('[31mred', Str::squish("\x1B[31mred"));
        $this->assertSame('Ali Khan', Str::squish("Ali\x1B Khan"));
    }

    public function testEveryOtherC0ControlAndDeleteGoTheSameWay(): void
    {
        foreach ([0x01, 0x02, 0x07, 0x08, 0x0E, 0x1A, 0x1F, 0x7F] as $byte) {
            $this->assertSame(
                'ab',
                Str::squish('a' . chr($byte) . 'b'),
                sprintf('0x%02X must not survive squish()', $byte)
            );
        }
    }

    /** The repair must not eat the characters a Pakistani or European name is made of. */
    public function testItLeavesRealTextAloneIncludingNonAsciiAndPunctuation(): void
    {
        $this->assertSame('Muhammad Bin Qasim', Str::squish("  Muhammad   Bin \t Qasim  "));
        $this->assertSame('Zülfiqar Türkiye', Str::squish("Zülfiqar\nTürkiye"));
        $this->assertSame("O'Neill-Smith (Jr.)", Str::squish("O'Neill-Smith  (Jr.)"));
        $this->assertSame('مُحَمَّد', Str::squish(' مُحَمَّد '), 'Arabic combining marks are letters, not controls');
        $this->assertSame('', Str::squish(null));
    }

    /**
     * The cap counts what is left, not what arrived. A control character that
     * was stripped must not still be spending one of the column's characters -
     * otherwise a 190-character name padded with NULs is silently cut short.
     */
    public function testTheLengthCapCountsTheCleanedString(): void
    {
        $noisy = "A\x00B\x00C\x00D\x00E";

        $this->assertSame('ABCDE', Str::squish($noisy));
        $this->assertSame('ABCDE', Str::limit(Str::squish($noisy), 5), 'five real characters still fit in five');
    }
}
