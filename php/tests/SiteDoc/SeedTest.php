<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\Validate;

/**
 * The sample document.
 *
 * It is not decoration: it seeds a fresh database, it is what the public site
 * falls back to when the database is unreachable, and it is the default every
 * missing field of an old document is filled from. A typo here reaches
 * customers through three separate doors.
 */
final class SeedTest extends SiteDocTestCase
{
    public function testTheSeedIsAValidPublishableDocument(): void
    {
        $this->assertSame([], Schema::check(Seed::site()->toArray()));
        $this->assertSame([], Validate::errors(Seed::site()));
        $this->assertSame([], Validate::warnings(Seed::site()));
        $this->assertFalse(Seed::site()->live, 'sample rates carry the sample notice until the owner ticks the box');
    }

    public function testTheRateCardIsTheOneThePrototypeWasCheckedAgainst(): void
    {
        $site = Seed::site();

        $this->assertSame(10, count($site->card->destinations));
        $this->assertSame(2, count($site->card->services));
        $this->assertSame('express', $site->card->services[0]->id);
        $this->assertSame('normal', $site->card->services[1]->id);

        $gb = $site->card->destination('gb');
        $this->assertNotNull($gb);
        $this->assertSame('United Kingdom', $gb?->name);
        $this->assertSame(4500.0, $gb?->rate('express')?->first);
        $this->assertSame(1100.0, $gb?->rate('express')?->addl);
        $this->assertSame("3\u{2013}5", $gb?->rate('express')?->days);

        // The prototype's seed gave every rate a flat document price as well. The
        // owner removed the document rate from the product on 2026-09-27, so a
        // fresh seed carries none, and nothing may put one back.
        foreach ($site->card->destinations as $dest) {
            foreach ($dest->rates as $serviceId => $rate) {
                $this->assertFalse(
                    array_key_exists('doc', $rate->toArray()),
                    $dest->id . ' / ' . $serviceId . ' seeds a document price'
                );
            }
        }
        $this->assertFalse(array_key_exists('docMaxKg', Seed::settings()), 'the seed still sets a document limit');

        $this->assertSame("T\u{00fc}rkiye", $site->card->destination('tr')?->name);
    }

    public function testTheGridSeedFillsEveryBoxUpToTheCargoThreshold(): void
    {
        $grid = Seed::gridSite();

        $this->assertSame('grid', $grid->settings()->pricingMode);
        $this->assertSame(25.0, $grid->settings()->maxKg);
        $this->assertSame([], Validate::errors($grid), 'a fresh grid site must be publishable');

        $boxes = $grid->card->destination('gb')?->rate('express')?->boxes() ?? [];
        $this->assertSame(25, count($boxes));

        // Converted from the slab prices: 4,500 for the first half kilo plus
        // 1,100 for every further half kilo, rounded to 10.
        $this->assertSame(5600.0, $boxes['1'] ?? null);
        $this->assertSame(10000.0, $boxes['3'] ?? null);
        $this->assertSame(1.0, (float) array_key_first($boxes), 'the boxes start at 1 kg');

        // Ascending, so no heavier parcel is ever cheaper than a lighter one.
        $previous = 0.0;
        foreach (Engine::gridWeights($grid->settings()) as $kg) {
            $price = $boxes[(string) $kg] ?? null;
            $this->assertNotNull($price, $kg . ' kg has no price');
            $this->assertTrue((float) $price > $previous, $kg . ' kg is not dearer than the box below it');
            $previous = (float) $price;
        }
    }

    public function testTheGridSeedSurvivesAStoreRoundTrip(): void
    {
        $doc = Seed::gridSite()->toArray();

        $this->assertSame([], Schema::check($doc));
        $this->assertSame($doc, Migrate::migrate(json_decode(json_encode($doc), true))->toArray());
    }

    /**
     * Every seeded sentence is still the TypeScript's own, except the two the
     * owner rewrote on 2026-09-27 when the document rate left the product.
     *
     * Those two are listed by name rather than skipped by pattern, so adding a
     * third divergence is a decision somebody has to write down here.
     */
    public function testEverySentenceIsWordForWordTheTypeScriptSeed(): void
    {
        $spec = dirname(dirname(dirname(__DIR__))) . '/src/lib/site/seed.ts';

        if (!is_file($spec)) {
            $this->skip('the TypeScript specification is not in this tree (it is not shipped to the host)');
        }

        $ts   = (string) file_get_contents($spec);
        $seen = 0;

        foreach ([Seed::company(), Seed::content()] as $group) {
            foreach ($group as $field => $value) {
                if (in_array($field, self::REWRITTEN_WITHOUT_DOCUMENTS, true)) {
                    continue;
                }
                foreach (explode("\n", (string) $value) as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $this->assertTrue(str_contains($ts, $line), $field . ' does not appear verbatim in seed.ts: ' . $line);
                    $seen++;
                }
            }
        }

        $this->assertTrue($seen > 20, 'the comparison actually read the copy');
    }

    /**
     * The two rewritten fields must not promise a price for documents, and the
     * TypeScript's own wording must be gone rather than merely edited around.
     */
    public function testTheRewrittenCopyPromisesNoDocumentPrice(): void
    {
        $content = Seed::content();

        foreach (self::REWRITTEN_WITHOUT_DOCUMENTS as $field) {
            $text = strtolower((string) ($content[$field] ?? ''));

            foreach (['flat rate', 'flat document', 'document rate', 'per envelope'] as $promise) {
                $this->assertFalse(str_contains($text, $promise), $field . ' still promises "' . $promise . '"');
            }
        }

        // Documents are still a thing the company carries; only the PRICE is gone.
        $this->assertTrue(str_contains($content['services'], 'Documents'), 'the Documents service line disappeared');
        $this->assertTrue(str_contains($content['services'], 'Priced by weight like any parcel'));
    }

    /**
     * The seed fields the owner's 2026-09-27 decision rewrote, so they no longer
     * match src/lib/site/seed.ts.
     *
     * @var list<string>
     */
    private const REWRITTEN_WITHOUT_DOCUMENTS = ['servicesLede', 'services'];
}
