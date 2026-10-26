<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\Rebase;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Tests\TestCase;

/**
 * The three-way merge that carries a draft onto a newer published version.
 * Pure arrays, no database: every rule in `Rebase`'s header has a case here.
 */
final class RebaseTest extends TestCase
{
    public function testOnlyTheLiveVersionChangedAFieldSoTheLiveValueIsKept(): void
    {
        $r = Rebase::merge(['a' => 1, 'b' => 1], ['a' => 2, 'b' => 1], ['a' => 1, 'b' => 1]);

        $this->assertSame(['a' => 2, 'b' => 1], $r['merged']);
        $this->assertSame([], $r['conflicts']);
    }

    public function testOnlyTheDraftChangedAFieldSoTheDraftValueIsKept(): void
    {
        $r = Rebase::merge(['a' => 1], ['a' => 1], ['a' => 3]);

        $this->assertSame(['a' => 3], $r['merged']);
    }

    public function testBothSidesChangedDifferentFieldsAndBothChangesSurvive(): void
    {
        $r = Rebase::merge(['a' => 1, 'b' => 1], ['a' => 2, 'b' => 1], ['a' => 1, 'b' => 3]);

        $this->assertSame(['a' => 2, 'b' => 3], $r['merged']);
        $this->assertSame([], $r['conflicts']);
    }

    public function testBothChangedTheSameFieldToTheSameValueIsNotAClash(): void
    {
        $r = Rebase::merge(['a' => 1], ['a' => 5], ['a' => 5]);

        $this->assertSame(['a' => 5], $r['merged']);
        $this->assertSame([], $r['conflicts']);
    }

    public function testAClashKeepsTheLiveValueAndReportsBothValues(): void
    {
        $r = Rebase::merge(['s' => ['addons' => '']], ['s' => ['addons' => 'Delivery | 1000 | on']], ['s' => ['addons' => 'Pickup | 500 | on']]);

        $this->assertSame(['s' => ['addons' => 'Delivery | 1000 | on']], $r['merged']);
        $this->assertCount(1, $r['conflicts']);
        $this->assertSame('s > addons', $r['conflicts'][0]['path']);
        $this->assertSame('Delivery | 1000 | on', $r['conflicts'][0]['live']);
        $this->assertSame('Pickup | 500 | on', $r['conflicts'][0]['draft']);
    }

    public function testAnIntegerAndTheSameFloatAreOnePrice(): void
    {
        $r = Rebase::merge(['p' => 52500], ['p' => 52500.0], ['p' => 60000]);

        $this->assertSame(['p' => 60000], $r['merged'], 'live did not really change the price, so the draft\'s edit must win');
        $this->assertSame([], $r['conflicts']);
    }

    public function testAKeyOnlyTheDraftAddedIsKeptAndOneOnlyTheDraftRemovedIsRemoved(): void
    {
        $added = Rebase::merge(['grid' => ['1' => 10]], ['grid' => ['1' => 10]], ['grid' => ['1' => 10, '2' => 20]]);
        $this->assertSame(['grid' => ['1' => 10, '2' => 20]], $added['merged']);

        $removed = Rebase::merge(['grid' => ['1' => 10, '21' => 5]], ['grid' => ['1' => 11, '21' => 5]], ['grid' => ['1' => 10]]);
        $this->assertSame(['grid' => ['1' => 11]], $removed['merged'], 'the live price change and the draft removal must both survive');
    }

    public function testCountriesAreMergedOneByOneSoEditsToDifferentCountriesNeverClash(): void
    {
        $base  = ['destinations' => [['id' => 'us', 'name' => 'US', 'p' => 1], ['id' => 'ca', 'name' => 'Canada', 'p' => 1]]];
        $live  = ['destinations' => [['id' => 'us', 'name' => 'US', 'p' => 2], ['id' => 'ca', 'name' => 'Canada', 'p' => 1]]];
        $draft = ['destinations' => [['id' => 'us', 'name' => 'US', 'p' => 1], ['id' => 'ca', 'name' => 'Canada', 'p' => 3]]];

        $r = Rebase::merge($base, $live, $draft);

        $this->assertSame([['id' => 'us', 'name' => 'US', 'p' => 2], ['id' => 'ca', 'name' => 'Canada', 'p' => 3]], $r['merged']['destinations']);
        $this->assertSame([], $r['conflicts']);
    }

    public function testACountryAddedOnEitherSideIsKeptAndTheDraftOrderWinsWhenTheDraftReordered(): void
    {
        $base  = ['d' => [['id' => 'a'], ['id' => 'b']]];
        $live  = ['d' => [['id' => 'a'], ['id' => 'b'], ['id' => 'l']]];
        $draft = ['d' => [['id' => 'b'], ['id' => 'a'], ['id' => 'x']]];

        $r = Rebase::merge($base, $live, $draft);

        $this->assertSame([['id' => 'b'], ['id' => 'a'], ['id' => 'x'], ['id' => 'l']], $r['merged']['d']);
    }

    public function testACountryTheDraftRemovedStaysRemovedUnlessLiveChangedIt(): void
    {
        $base = ['d' => [['id' => 'a', 'p' => 1], ['id' => 'b', 'p' => 1]]];

        $gone = Rebase::merge($base, $base, ['d' => [['id' => 'a', 'p' => 1]]]);
        $this->assertSame([['id' => 'a', 'p' => 1]], $gone['merged']['d']);

        $clash = Rebase::merge($base, ['d' => [['id' => 'a', 'p' => 1], ['id' => 'b', 'p' => 9]]], ['d' => [['id' => 'a', 'p' => 1]]]);
        $this->assertSame([['id' => 'a', 'p' => 1], ['id' => 'b', 'p' => 9]], $clash['merged']['d'], 'a removal must not silently drop a country live had just changed');
        $this->assertCount(1, $clash['conflicts']);
    }

    public function testAClashInsideACountryIsNamedByTheCountry(): void
    {
        $base  = ['destinations' => [['id' => 'us', 'name' => 'United States', 'rates' => ['normal' => ['grid' => ['21' => 100]]]]]];
        $live  = ['destinations' => [['id' => 'us', 'name' => 'United States', 'rates' => ['normal' => ['grid' => ['21' => 200]]]]]];
        $draft = ['destinations' => [['id' => 'us', 'name' => 'United States', 'rates' => ['normal' => ['grid' => ['21' => 300]]]]]];

        $r = Rebase::merge($base, $live, $draft);

        $this->assertSame('destinations > United States > rates > normal > grid > 21', $r['conflicts'][0]['path']);
    }

    public function testAListWithoutIdsIsOneValue(): void
    {
        $r = Rebase::merge(['reviews' => [['q' => 'a']]], ['reviews' => [['q' => 'b']]], ['reviews' => [['q' => 'c']]]);

        $this->assertSame([['q' => 'b']], $r['merged']['reviews']);
        $this->assertCount(1, $r['conflicts']);
    }

    public function testNothingChangedOnEitherSideGivesBackTheLiveDocumentUntouched(): void
    {
        $doc = Seed::data();

        $r = Rebase::merge($doc, $doc, $doc);

        $this->assertSame($doc, $r['merged']);
        $this->assertSame([], $r['conflicts']);
    }

    /**
     * The real case, in miniature: speedat.co's version 18 added the Delivery
     * add-on and switched the dates off; the owner's draft, branched from 17,
     * repriced the US. The merge must keep all three and undo none.
     */
    public function testTheLiveSiteCaseKeepsTheOwnersPricesAndVersion18sSettings(): void
    {
        $v17 = Seed::data();
        $v17['settings']['addons']  = '';
        $v17['settings']['showEta'] = true;

        $v18 = $v17;
        $v18['settings']['addons']  = 'Delivery | 1000 | on';
        $v18['settings']['showEta'] = false;

        $draft = $v17;
        foreach ($draft['destinations'] as $i => $d) {
            if ($d['id'] === 'us') {
                $draft['destinations'][$i]['name'] = 'United States (edited)';
            }
        }

        $r = Rebase::merge($v17, $v18, $draft);

        $this->assertSame('Delivery | 1000 | on', $r['merged']['settings']['addons'], 'the delivery charge from version 18 was undone');
        $this->assertFalse($r['merged']['settings']['showEta'], 'the dates switched off in version 18 came back on');
        $names = array_column($r['merged']['destinations'], 'name', 'id');
        $this->assertSame('United States (edited)', $names['us'], 'the owner\'s edit was lost');
        $this->assertSame([], $r['conflicts']);
    }
}
