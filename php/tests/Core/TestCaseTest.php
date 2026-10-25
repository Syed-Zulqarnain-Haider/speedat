<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Tests\AssertionFailed;
use Speedat\Tests\TestCase;

/**
 * The test framework's own tests.
 *
 * Two reasons this file exists rather than being obviously unnecessary.
 *
 * A MISSING HELPER IS AN ERROR, NOT A FAILURE. `$this->assertCount(2, $rows)`
 * on a TestCase that has no assertCount() is an undefined-method Error; the
 * runner reports it as [ERR] next to the genuine failures and somebody spends
 * the afternoon looking for a bug in $rows. tests/Import/ImportTestCase.php had
 * its own private copy, which is exactly how a helper stays missing from the
 * base class for months.
 *
 * AND A FAILURE MESSAGE THAT LIES COSTS MORE THAN NO MESSAGE. assertSame()
 * compares with `!==`, so 500 and 500.0 do not match - but both used to print
 * as "500", and "expected 500, got 500" reads as a broken framework. The reader
 * stops trusting the runner instead of looking at the float.
 */
final class TestCaseTest extends TestCase
{
    public function testAssertCountExistsAndPrintsBothCounts(): void
    {
        // The helper the suite was already calling before it existed.
        $this->assertCount(3, ['a', 'b', 'c']);
        $this->assertCount(0, []);
        $this->assertCount(2, new \ArrayObject(['x', 'y']), 'a Countable, not only an array');

        $message = $this->messageFrom(fn () => $this->assertCount(2, ['only-one' => 1]));

        $this->assertStringContains('expected 2 item(s)', $message);
        $this->assertStringContains('got 1', $message, 'both counts, so the reader does not have to go and count');
        $this->assertStringContains('only-one', $message, 'and the keys, which is what is usually wrong');
    }

    public function testACountMessageKeepsTheCallersOwnWords(): void
    {
        $message = $this->messageFrom(
            fn () => $this->assertCount(1, [], 'one lead should have been written')
        );

        $this->assertStringContains('one lead should have been written', $message);
        $this->assertStringContains('expected 1 item(s), got 0', $message);
    }

    /**
     * The "expected 500, got 500" failure, fixed.
     *
     * int and float are the pair that actually happens: a total that went
     * through a division comes back as a float and stops being identical to the
     * integer the sheet prints.
     */
    public function testAFailureMessageSaysWhichTypeItGot(): void
    {
        $message = $this->messageFrom(fn () => $this->assertSame(500, 500.0));

        $this->assertStringContains('int(500)', $message, 'the expected value carries its type');
        $this->assertStringContains('float(500.0)', $message, 'and so does the one that arrived');
        $this->assertStringNotContains('expected 500, got 500', $message, 'the message that sent people hunting');
    }

    public function testTheOtherTypesStillReadTheWayTheyDid(): void
    {
        // A string keeps its quotes, which already tell it apart from a number.
        $this->assertStringContains('"500"', $this->messageFrom(fn () => $this->assertSame('500', 500)));
        $this->assertStringContains('int(500)', $this->messageFrom(fn () => $this->assertSame('500', 500)));

        $this->assertStringContains('true', $this->messageFrom(fn () => $this->assertSame(true, false)));
        $this->assertStringContains('null', $this->messageFrom(fn () => $this->assertSame(null, 1)));
        $this->assertStringContains('array(2)', $this->messageFrom(fn () => $this->assertSame([1, 2], 1)));

        // A float that is a whole number is the case that used to collapse; one
        // that is not was always readable and must stay so.
        $this->assertStringContains('float(0.5)', $this->messageFrom(fn () => $this->assertSame(1, 0.5)));
    }

    // ---------------------------------------------------------------- helpers

    /** Run an assertion that must fail, and hand back what it said. */
    private function messageFrom(\Closure $failing): string
    {
        try {
            $failing();
        } catch (AssertionFailed $e) {
            return $e->getMessage();
        }

        $this->fail('that assertion was supposed to fail, and did not');
    }
}
