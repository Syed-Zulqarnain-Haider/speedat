<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Domain\Tracking\Checkpoint;
use Speedat\Tests\TestCase;

/**
 * Checkpoint::place() is the last filter between a carrier's location field
 * and a public page, so its job is mostly saying what it throws away.
 */
final class CheckpointTest extends TestCase
{
    public function testAPlaceKeepsTheTownAndDropsWhatFollowsANumber(): void
    {
        $cases = [
            'DUBAI'                         => 'Dubai',
            'Lahore 54000'                  => 'Lahore',
            'LONDON HEATHROW'               => 'London Heathrow',
            'Hub 4, Main Blvd'              => 'Hub',
            'Karachi +92 300 1234567'       => 'Karachi',
            'NEW YORK, NY 10001'            => 'New York, Ny',
            "Sharjah\u{2014}call me"        => 'Sharjah Call Me',
            'São Paulo'                     => 'São Paulo',
            '  ,  '                         => '',
            '12 Mall Road'                  => '',
            'name@example.com'              => '',
            'Doha ali@example.qa'           => 'Doha',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, Checkpoint::place($raw), '"' . $raw . '"');
        }
    }

    public function testAPlaceIsNeverLongerThanSixtyCharacters(): void
    {
        $this->assertSame(60, mb_strlen(Checkpoint::place(str_repeat('Abcdefghij ', 20))));
    }

    public function testCountryIsTwoLettersOrNothing(): void
    {
        $this->assertSame('AE', Checkpoint::country('ae'));
        $this->assertSame('PK', Checkpoint::country(' PK '));
        $this->assertSame('', Checkpoint::country('UAE'));
        $this->assertSame('', Checkpoint::country('1A'));
        $this->assertSame('', Checkpoint::country(''));
    }
}
