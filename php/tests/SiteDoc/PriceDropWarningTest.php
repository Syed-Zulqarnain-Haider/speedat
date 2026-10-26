<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Domain\SiteDoc\Validate;

/**
 * "A heavier parcel cheaper than a lighter one", on speedat.co's real UK
 * Express Saver row: 13 kg 27,300, 14 kg 2,900 (a dropped zero), 15 kg 26,500.
 * The warning used to stop at the first drop and miss that 15 kg is cheaper
 * than 13 kg too.
 */
final class PriceDropWarningTest extends SiteDocTestCase
{
    public function testEveryDropIsReportedAgainstTheDearestLighterWeight(): void
    {
        $doc = $this->seedArray();
        $doc['settings']['pricingMode'] = 'grid';
        foreach ($doc['destinations'] as $i => $d) {
            if ($d['id'] === 'gb') {
                $grid = [];
                foreach (range(1, 25) as $kg) {
                    $grid[(string) $kg] = 10000 + $kg * 1000;
                }
                $grid['13'] = 27300;
                $grid['14'] = 2900;
                $grid['15'] = 26500;
                $doc['destinations'][$i]['rates']['express']['grid'] = $grid;
            }
        }

        $warnings = implode("\n", Validate::warnings(SiteData::fromArray($doc)));

        $this->assertStringContains('14 kg (PKR 2,900) is cheaper than 13 kg (PKR 27,300)', $warnings);
        $this->assertStringContains('15 kg (PKR 26,500) is cheaper than 13 kg (PKR 27,300)', $warnings);
        $this->assertStringNotContains('18 kg', $warnings, 'a box above the dearest one so far (28,000 > 27,300) was reported');
    }
}
