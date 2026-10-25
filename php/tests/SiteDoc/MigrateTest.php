<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\Validate;

/**
 * Loading an old document. Same cases as src/lib/site/migrate.test.ts.
 *
 * This is the test that keeps the version history honest: "restore version 1"
 * has to render, and it has to render in today's words where the words were
 * never the owner's to begin with.
 */
final class MigrateTest extends SiteDocTestCase
{
    public function testTextThatStillReadsAsAnEarlierRoundsDefaultBecomesTheCurrentDefault(): void
    {
        $stored                  = Seed::content();
        $stored['heroTitle']     = 'Send anything *abroad*. See your price now.';
        $stored['heroSub']       = "Tap your country and the weight. Your price appears \u{2014} then book on WhatsApp.";
        $stored['routesTitle']   = 'Where we deliver';
        $stored['ctaTitle']      = 'Ready to send something?';
        $stored['stepsTitle']    = 'Three taps, one price';

        $up = Migrate::upgradeContent($stored);

        $this->assertSame('', $up['heroTitle']);
        $this->assertSame('', $up['heroSub']);
        $this->assertSame('Rates', $up['routesTitle']);
        $this->assertSame('', $up['ctaTitle']);
        $this->assertSame('', $up['stepsTitle']);
    }

    public function testEveryWordTheOwnerTypedHimselfIsKept(): void
    {
        $stored               = Seed::content();
        $stored['heroTitle']  = 'Lahore to the world.';
        $stored['faq']        = 'Do you ship on Sunday? | No.';
        $stored['routesNote'] = 'Prices per kilo';

        $up = Migrate::upgradeContent($stored);

        $this->assertSame('Lahore to the world.', $up['heroTitle']);
        $this->assertSame('Do you ship on Sunday? | No.', $up['faq']);
        $this->assertSame('Prices per kilo', $up['routesNote']);
    }

    public function testNoCurrentDefaultIsListedAsRetiredSoTheUpgradeIsIdempotent(): void
    {
        $seed = Seed::content();

        foreach (Migrate::retiredDefaults() as $key => $retired) {
            $current = $seed[$key] ?? '';
            if ($current !== '') {
                $this->assertFalse(
                    in_array($current, $retired, true),
                    $key . "'s current default is also listed as retired, so it would be replaced by itself forever"
                );
            }
        }

        $this->assertSame($seed, Migrate::upgradeContent($seed));
    }

    public function testMissingContentKeysAreFilledAndRetiredDefaultsUpgradedInOnePass(): void
    {
        $out = Migrate::migrate(['content' => ['heroTitle' => 'Where we fly from Lahore'], 'destinations' => []]);

        // "Where we fly from Lahore" was v1's routesTitle, not a heroTitle: only
        // the field's own retired list counts, so this one is the owner's.
        $this->assertSame('Where we fly from Lahore', $out->content->heroTitle);
        $this->assertSame(Seed::content()['promise'], $out->content->promise);

        $doc                            = Seed::data();
        $doc['content']['routesTitle']   = 'Where we deliver';
        $doc['content']['servicesTitle'] = 'Two ways to send';
        $v2                              = Migrate::migrate($doc);

        $this->assertSame('Rates', $v2->content->routesTitle);
        $this->assertSame('', $v2->content->servicesTitle);
    }

    public function testAWholeDocumentPublishedFromAnEarlierSeedComesOntoTheCurrentCopy(): void
    {
        $old            = Seed::content();
        $old['address'] = 'Shop 4, Main Market';
        $old['hours']   = '9 to 5';
        $old['phone2']  = '+92 300 0000000';

        // Every retired field at its last (v2) default, as the dev host stored it before v3.
        foreach (Migrate::retiredDefaults() as $key => $retired) {
            $old[$key] = $retired[count($retired) - 1];
        }

        $out = Migrate::migrate(['live' => true, 'content' => $old, 'destinations' => []]);

        foreach (Migrate::retiredDefaults() as $key => $_) {
            $this->assertSame(Seed::content()[$key], $out->content->get($key), $key . ' was not brought forward');
        }

        $this->assertSame('Shop 4, Main Market', $out->content->address);
        $this->assertSame('9 to 5', $out->content->hours);
        $this->assertSame('+92 300 0000000', $out->content->phone2);
        $this->assertTrue($out->live);

        // v2 marked emphasis with asterisks; v3 prints every sentence as typed,
        // so a surviving *word* would appear on the page with its stars.
        foreach ($out->content->toArray() as $field => $value) {
            $this->assertSame(0, preg_match('/\*[^*]+\*/', $value), $field . ' still carries v2 accent asterisks');
        }
    }

    public function testDefaultsAreFilledForOldSnapshotsAndTheCutoffIsNormalised(): void
    {
        $m = Migrate::migrate(['company' => ['name' => 'X'], 'settings' => ['cutoffHour' => ''], 'destinations' => []]);

        $this->assertSame('X', $m->company->name);
        $this->assertSame(Seed::company()['whatsapp'], $m->company->whatsapp);
        $this->assertNull($m->settings()->cutoffHour);
        $this->assertSame(2, count($m->card->services));
        $this->assertFalse($m->live);

        $doc                          = Seed::data();
        $doc['settings']['cutoffHour'] = 9;
        $this->assertSame(9.0, Migrate::migrate($doc)->settings()->cutoffHour);

        // A document that never carried a cutoff has none: inheriting the seed's
        // 3 pm would invent a same-day deadline the owner never promised.
        $this->assertNull(Migrate::migrate(['destinations' => []])->settings()->cutoffHour);
    }

    public function testABlankDocumentStillPricesNothingButLoads(): void
    {
        $m = Migrate::migrate([]);

        $this->assertSame([], $m->card->destinations);
        $this->assertSame(2, count($m->card->services));
        $this->assertSame('slab', $m->settings()->pricingMode);
        $this->assertSame([], $m->importProfiles);
    }

    /**
     * A version published before 2026-09-27 carries `settings.docMaxKg` and a
     * `doc` price on most rates. The owner removed the document rate from the
     * product that day, so migrate() must DROP both rather than choke on them -
     * and the version must still render and still be publishable, or the version
     * history stops being a promise.
     */
    public function testAStoredDocumentRateIsDroppedRatherThanBreakingTheVersion(): void
    {
        $v1 = Seed::data();

        // Put the retired shape back exactly as a v1 row holds it.
        $v1['settings']['docMaxKg']                          = 0.5;
        $v1['destinations'][0]['rates']['express']['doc']    = 3900;
        $v1['destinations'][0]['rates']['normal']['doc']     = 2800;
        $v1['destinations'][1]['rates']['express']['doc']    = 4500;

        $migrated = Migrate::migrate($v1);
        $out      = $migrated->toArray();

        $this->assertFalse(
            array_key_exists('docMaxKg', $out['settings']),
            'the retired document weight limit survived the migration'
        );

        foreach ($out['destinations'] as $dest) {
            foreach ($dest['rates'] as $serviceId => $rate) {
                $this->assertFalse(
                    array_key_exists('doc', $rate),
                    $dest['id'] . ' / ' . $serviceId . ' kept a document price'
                );
            }
        }

        // The weight prices are untouched, and the version still publishes.
        $this->assertSame(4500.0, $migrated->card->destinations[0]->rate('express')?->first);
        $this->assertSame([], Validate::errors($migrated));

        // Migrating the already-migrated document is a no-op, so republishing an
        // old version cannot reintroduce the key.
        $this->assertSame($out, Migrate::migrate($out)->toArray());
    }

    public function testAnUnknownPricingModeFallsBackToSlab(): void
    {
        $doc                            = Seed::data();
        $doc['settings']['pricingMode'] = 'per-kilo';
        $this->assertSame('slab', Migrate::migrate($doc)->settings()->pricingMode);

        $doc['settings']['pricingMode'] = 'grid';
        $this->assertSame('grid', Migrate::migrate($doc)->settings()->pricingMode);
    }

    public function testMigratingTheSeedChangesNothingAndDoesSoRepeatably(): void
    {
        $once  = Migrate::migrate(Seed::data())->toArray();
        $twice = Migrate::migrate($once)->toArray();

        $this->assertSame(Seed::site()->toArray(), $once, 'the seed is already in the current shape');
        $this->assertSame($once, $twice, 'migrating an already-migrated document is a no-op');
    }
}
