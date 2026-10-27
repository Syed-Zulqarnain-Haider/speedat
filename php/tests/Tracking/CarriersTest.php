<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Domain\Tracking\Carriers;
use Speedat\Tests\TestCase;

/**
 * The carrier column is free text the office has typed for months. These are
 * the spellings that must keep tracking, and the near-misses that must not.
 */
final class CarriersTest extends TestCase
{
    public function testTheWaysTheOfficeWritesACarrierAllMatch(): void
    {
        $cases = [
            'DHL'               => 'dhl',
            'dhl express'       => 'dhl',
            'DHL Express (UK)'  => 'dhl',
            'FedEx'             => 'fedex',
            'Fed Ex'            => 'fedex',
            'UPS'               => 'ups',
            'DPD'               => 'dpd',
            'Aramex'            => 'aramex',
            'SkyNet'            => 'skynet',
            'Sky Net Worldwide' => 'skynet',
            'TCS'               => 'tcs',
            'Leopards Courier'  => 'leopards',
            'LCS'               => 'leopards',
            'M&P'               => 'mp',
            'M & P'             => 'mp',
            'M and P'           => 'mp',
            'Trax'              => 'trax',
            'TeamGlobal'        => 'teamglobal',
            'Team Global Express' => 'teamglobal',
        ];

        foreach ($cases as $typed => $key) {
            $this->assertSame($key, Carriers::match($typed), $typed);
        }
    }

    public function testAWordInsideAnotherWordIsNotACarrier(): void
    {
        foreach (['Emirates SkyCargo', 'groups cargo', 'Startrax', '', '   ', 'Ali\'s cousin'] as $typed) {
            $this->assertNull(Carriers::match($typed), '"' . $typed . '"');
        }
    }

    public function testCodesAreSeventeenTrackOnesAndTeamGlobalIsManual(): void
    {
        $this->assertSame(100001, Carriers::trackCode('DHL'));
        $this->assertSame(100003, Carriers::trackCode('FedEx'));
        $this->assertSame(100002, Carriers::trackCode('UPS'));
        $this->assertSame(0, Carriers::trackCode('DPD'), 'DPD is a dozen national companies: let 17TRACK detect it');
        $this->assertSame(100260, Carriers::trackCode('TCS'));
        $this->assertNull(Carriers::trackCode('TeamGlobal'), 'no tracking service carries TeamGlobal Pakistan');
        $this->assertNull(Carriers::trackCode('Emirates SkyCargo'));
    }

    public function testDisplayNames(): void
    {
        $this->assertSame('DHL Express', Carriers::displayName('dhl'));
        $this->assertSame('M&P', Carriers::displayName('m and p'));
        $this->assertSame('Emirates SkyCargo', Carriers::displayName('  Emirates   SkyCargo '));
        $this->assertTrue(in_array('TeamGlobal', Carriers::names(), true));
        $this->assertFalse(in_array('TeamGlobal', Carriers::trackedNames(), true));
        $this->assertTrue(in_array('DHL Express', Carriers::trackedNames(), true));
    }
}
