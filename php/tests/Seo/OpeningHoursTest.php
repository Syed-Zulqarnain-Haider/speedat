<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\OpeningHours;
use Speedat\Tests\TestCase;

/**
 * The hours parser, including the cases where it must refuse.
 *
 * Refusing is the point: an omitted `openingHoursSpecification` costs a little
 * rich-result polish, and a wrong one sends somebody to a closed office.
 */
final class OpeningHoursTest extends TestCase
{
    public function testTheOwnersOwnLineParses(): void
    {
        $spec = OpeningHours::parseLine('Monday to Saturday, 9 am – 7 pm');

        $this->assertNotNull($spec);
        $this->assertSame('09:00', $spec['opens']);
        $this->assertSame('19:00', $spec['closes']);
        $this->assertSame(6, count($spec['dayOfWeek']));
        $this->assertSame('Monday', $spec['dayOfWeek'][0]);
        $this->assertSame('Saturday', $spec['dayOfWeek'][5]);
    }

    public function testAbbreviationsAndHyphensParse(): void
    {
        $spec = OpeningHours::parseLine('Mon-Sat 09:00-19:00');

        $this->assertNotNull($spec);
        $this->assertSame('09:00', $spec['opens']);
        $this->assertSame('19:00', $spec['closes']);
        $this->assertSame(6, count($spec['dayOfWeek']));
    }

    public function testARangeThatWrapsPastSundayStillCoversTheRightDays(): void
    {
        $spec = OpeningHours::parseLine('Saturday to Tuesday, 10 am to 4 pm');

        $this->assertNotNull($spec);
        $this->assertSame(4, count($spec['dayOfWeek']));
        $this->assertSame('Saturday', $spec['dayOfWeek'][0]);
        $this->assertSame('Sunday', $spec['dayOfWeek'][1]);
        $this->assertSame('Tuesday', $spec['dayOfWeek'][3]);
    }

    public function testAListOfDaysIsNotReadAsARange(): void
    {
        $spec = OpeningHours::parseLine('Monday, Wednesday and Friday 9 am to 1 pm');

        $this->assertNotNull($spec);
        $this->assertSame(3, count($spec['dayOfWeek']));
        $this->assertSame('Monday', $spec['dayOfWeek'][0]);
        $this->assertSame('Wednesday', $spec['dayOfWeek'][1]);
        $this->assertSame('Friday', $spec['dayOfWeek'][2]);
    }

    public function testASingleDayParses(): void
    {
        $spec = OpeningHours::parseLine('Sunday 11 am - 3 pm');

        $this->assertNotNull($spec);
        $this->assertSame(1, count($spec['dayOfWeek']));
        $this->assertSame('Sunday', $spec['dayOfWeek'][0]);
        $this->assertSame('11:00', $spec['opens']);
        $this->assertSame('15:00', $spec['closes']);
    }

    public function testMinutesAndNoonAndMidnightAreHandled(): void
    {
        $spec = OpeningHours::parseLine('Monday to Friday, 9:30 am to 12 pm');
        $this->assertNotNull($spec);
        $this->assertSame('09:30', $spec['opens']);
        $this->assertSame('12:00', $spec['closes']);

        $overnight = OpeningHours::parseLine('Friday 8 pm to 12 am');
        $this->assertNotNull($overnight);
        $this->assertSame('20:00', $overnight['opens']);
        $this->assertSame('00:00', $overnight['closes']);
    }

    public function testSeveralLinesEachBecomeTheirOwnSpecification(): void
    {
        $specs = OpeningHours::parse("Monday to Friday, 9 am - 7 pm\nSaturday, 10 am - 4 pm");

        $this->assertSame(2, count($specs));
        $this->assertSame(5, count($specs[0]['dayOfWeek']));
        $this->assertSame(1, count($specs[1]['dayOfWeek']));
    }

    public function testAnAmbiguousBareRangeIsRefusedRatherThanGuessed(): void
    {
        // "9 - 7" could be 09:00-19:00 or a typo. Refuse.
        $this->assertNull(OpeningHours::parseLine('Monday to Saturday, 9 - 7'));
    }

    public function testProseWithNoDaysOrNoTimesIsRefused(): void
    {
        $this->assertNull(OpeningHours::parseLine('whenever we feel like it'));
        $this->assertNull(OpeningHours::parseLine('9 am to 7 pm'));
        $this->assertNull(OpeningHours::parseLine('Monday to Saturday'));
        $this->assertSame([], OpeningHours::parse(''));
    }

    public function testAnImpossibleClockFaceIsRefused(): void
    {
        $this->assertNull(OpeningHours::parseLine('Monday 25:00 to 26:00'));
        $this->assertNull(OpeningHours::parseLine('Monday 9:75 am to 7 pm'));
    }
}
