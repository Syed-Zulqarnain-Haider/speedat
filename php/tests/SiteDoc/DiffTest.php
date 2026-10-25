<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\DiffLine;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Domain\SiteDoc\Validate;

/**
 * The publish review, line by line, against the cases in
 * src/lib/site/diff.test.ts.
 *
 * Every string asserted here is shown to the owner on the screen where he
 * decides whether to publish, and kept in the audit log afterwards. They are
 * compared exactly, middle dots and en dashes included: "nearly the same
 * wording" in two halves of one product is two products.
 */
final class DiffTest extends SiteDocTestCase
{
    private const MIDDOT = "\u{00b7}";
    private const NDASH  = "\u{2013}";
    // The owner replaced the em dash with a plain hyphen in admin text (2026-10-11).
    private const DASH   = '-';
    private const HELLIP = "\u{2026}";

    public function testIdenticalDocumentsReportNothing(): void
    {
        $d = Diff::of(Seed::site(), $this->site($this->seedArray()));

        $this->assertSame(0, $d->count);
        $this->assertSame(0, count($d->lines));
        $this->assertSame('0 changes', Diff::summarise($d));
    }

    public function testRateChangesAreDescribedAndBigMovesFlagged(): void
    {
        $b = $this->seedArray();
        $b['destinations'][0]['rates']['express']['first'] = 4600; // +2%
        $b['destinations'][1]['rates']['normal']['addl']   = 1900; // +100%

        $d = Diff::of(Seed::site(), $this->site($b));

        $this->assertSame(2, $d->count);
        $this->assertSame(['gb', 'us'], $d->changedDestinations());

        $first = $d->lines[0];
        $this->assertSame(DiffLine::RATE, $first->kind);
        $this->assertSame('United Kingdom ' . self::MIDDOT . ' Express first slab', $first->label);
        $this->assertSame('PKR 4,500', $first->old);
        $this->assertSame('PKR 4,600', $first->new);
        $this->assertNull($first->flag, 'a 2% move is not worth a warning');

        $this->assertSame('+100% ' . self::DASH . ' please double-check', $d->lines[1]->flag);
        $this->assertSame('1 change: 1 destination', Diff::summarise(Diff::of(Seed::site(), $this->only($b, 'gb'))));
    }

    public function testAddedRemovedRenamedAndHiddenDestinations(): void
    {
        $b = $this->seedArray();

        $b['destinations'] = array_values(array_filter(
            $b['destinations'],
            static fn (array $x): bool => $x['id'] !== 'my'
        ));
        $b['destinations'][0]['name']   = 'UK';
        $b['destinations'][1]['active'] = false;
        $b['destinations'][]            = [
            'id' => 'fr', 'name' => 'France', 'active' => true,
            'rates' => ['express' => ['first' => 1, 'addl' => 1]],
        ];

        $d = Diff::of(Seed::site(), $this->site($b));

        $kinds = [];
        foreach ($d->lines as $line) {
            $kinds[] = $line->kind;
        }
        sort($kinds);

        $this->assertSame(['added', 'removed', 'renamed', 'visibility'], $kinds);
        $this->assertSame('4 changes: 4 destinations', Diff::summarise($d));
    }

    public function testSettingsTextLiveFlagAndImportLayouts(): void
    {
        $b                            = $this->seedArray();
        $b['settings']['taxPct']      = 5;
        $b['content']['heroTitle']    = 'New title';
        $b['live']                    = true;
        $b['importProfiles'][]        = [
            'signature' => 'a|b', 'name' => 'rates.xlsx', 'map' => [],
            'savedAt' => '2026-01-01', 'cost' => false, 'margin' => 0, 'mround' => 1,
        ];

        $d = Diff::of(Seed::site(), $this->site($b));

        $labels = [];
        foreach ($d->lines as $line) {
            $labels[] = $line->label;
        }

        $this->assertSame(
            ['taxPct', 'Website text ' . self::MIDDOT . ' heroTitle', 'Rates are live', 'Import layout remembered'],
            $labels
        );
        $this->assertSame('', $d->lines[1]->old);
        $this->assertSame('New title', $d->lines[1]->new);
        $this->assertSame('0', $d->lines[0]->old);
        $this->assertSame('5', $d->lines[0]->new);
        $this->assertSame('false', $d->lines[2]->old);
        $this->assertSame('true', $d->lines[2]->new);
        $this->assertSame('rates.xlsx', $d->lines[3]->new);
        $this->assertSame('4 changes: settings or text, import layout', Diff::summarise($d));
    }

    public function testTheChangedLineOfAMultiLineFieldIsShownNotItsFirstNinetyCharacters(): void
    {
        $lines    = $this->faqLines();
        $b        = $this->seedArray();
        $changed  = $lines;
        $changed[2] .= ' ZZTEST';
        $b['content']['faq'] = implode("\n", $changed);

        $d = Diff::of(Seed::site(), $this->site($b));

        $this->assertSame(1, $d->count);
        $this->assertSame('Website text ' . self::MIDDOT . ' faq (line 3)', $d->lines[0]->label);
        $this->assertSame($lines[2], $d->lines[0]->old);
        $this->assertSame($lines[2] . ' ZZTEST', $d->lines[0]->new);
    }

    public function testALongLineIsWindowedAroundTheChangeSoOldAndNewNeverReadTheSame(): void
    {
        $lines = $this->faqLines();
        $this->assertTrue(mb_strlen($lines[0], 'UTF-8') > 120, 'the first FAQ line is long enough to be windowed');

        $b       = $this->seedArray();
        $changed = $lines;
        $changed[0] = str_replace('shows the working.', 'shows the ZZTEST working.', $changed[0]);
        $b['content']['faq'] = implode("\n", $changed);

        $line = Diff::of(Seed::site(), $this->site($b))->lines[0];

        $this->assertSame('Website text ' . self::MIDDOT . ' faq (line 1)', $line->label);
        $this->assertNotSame($line->old, $line->new);
        $this->assertStringContains('ZZTEST', (string) $line->new);
        $this->assertStringContains('shows the working.', (string) $line->old);
        $this->assertTrue(str_starts_with((string) $line->old, self::HELLIP));
        $this->assertTrue(mb_strlen((string) $line->new, 'UTF-8') <= 92);
    }

    public function testAShortLineThatGrowsPastTheWindowIsCutFromTheFrontOnlyAsFarAsNeeded(): void
    {
        $lines   = $this->faqLines();
        $g       = $this->seedArray();
        $changed = $lines;
        $changed[2] .= ' ZZTEST-LINE3';
        $g['content']['faq'] = implode("\n", $changed);

        $line = Diff::of(Seed::site(), $this->site($g))->lines[0];

        $this->assertSame('Website text ' . self::MIDDOT . ' faq (line 3)', $line->label);
        $this->assertSame(
            self::HELLIP . 'ays duties at the destination? | The receiver, when the country charges them.',
            $line->old
        );
        $this->assertSame(
            self::HELLIP . 'ays duties at the destination? | The receiver, when the country charges them. ZZTEST-LINE3',
            $line->new
        );
        $this->assertSame(91, mb_strlen((string) $line->new, 'UTF-8'), 'the ellipsis plus a full 90-character window');
    }

    public function testASingleLineFieldIsWindowedTheSameWayWithNoLineMarker(): void
    {
        $c = $this->seedArray();
        $c['settings']['disclaimer'] = str_replace(
            'customs delays.',
            'ZZTEST customs delays.',
            (string) $c['settings']['disclaimer']
        );

        $line = Diff::of(Seed::site(), $this->site($c))->lines[0];

        $this->assertSame('disclaimer', $line->label);
        $this->assertStringContains('ZZTEST', (string) $line->new);
        $this->assertNotSame($line->old, $line->new);
    }

    public function testAddedRemovedAndMultiLineChangesAreNamed(): void
    {
        $b                        = $this->seedArray();
        $b['settings']['holidays'] = "2026-12-25 | Quaid-e-Azam Day\n2027-03-23";
        $added                     = Diff::of(Seed::site(), $this->site($b))->lines[0];

        $this->assertSame('holidays (lines 1' . self::NDASH . '2 added)', $added->label);
        $this->assertSame('', $added->old);
        $this->assertSame("2026-12-25 | Quaid-e-Azam Day \u{23ce} 2027-03-23", $added->new);

        $lines = $this->faqLines();

        $c                   = $this->seedArray();
        $withoutThird        = $lines;
        unset($withoutThird[2]);
        $c['content']['faq'] = implode("\n", array_values($withoutThird));
        $removed             = Diff::of(Seed::site(), $this->site($c))->lines[0];

        $this->assertSame('Website text ' . self::MIDDOT . ' faq (line 3 removed)', $removed->label);
        $this->assertSame($lines[2], $removed->old);
        $this->assertSame('', $removed->new);

        $e        = $this->seedArray();
        $inserted = $lines;
        array_splice($inserted, 2, 0, ['Extra question? | Extra answer.']);
        $e['content']['faq'] = implode("\n", $inserted);
        $insert              = Diff::of(Seed::site(), $this->site($e))->lines[0];

        $this->assertSame('Website text ' . self::MIDDOT . ' faq (line 3 added)', $insert->label);
        $this->assertSame('', $insert->old);
        $this->assertSame('Extra question? | Extra answer.', $insert->new);

        $f       = $this->seedArray();
        $spread  = $lines;
        $spread[1] = 'Q2? | A2.';
        $spread[3] = 'Q4? | A4.';
        $f['content']['faq'] = implode("\n", $spread);

        $this->assertSame(
            'Website text ' . self::MIDDOT . ' faq (lines 2' . self::NDASH . '4)',
            Diff::of(Seed::site(), $this->site($f))->lines[0]->label
        );
    }

    public function testGridPricesAreComparedOneKilogramAtATime(): void
    {
        $a = Seed::gridSite();

        $b = $a->toArray();
        // 3 kg is 4,500 + 5 steps of 1,100 = 10,000 in the seed; a third of that
        // is the kind of move the owner has to see before it reaches customers.
        $b['destinations'][0]['rates']['express']['grid']['3'] = 3000;

        $d = Diff::of($a, $this->site($b));

        $this->assertSame(1, $d->count);
        $this->assertSame('United Kingdom ' . self::MIDDOT . ' Express 3 kg', $d->lines[0]->label);
        $this->assertSame('PKR 10,000', $d->lines[0]->old);
        $this->assertSame('PKR 3,000', $d->lines[0]->new);
        $this->assertSame('-70% ' . self::DASH . ' please double-check', $d->lines[0]->flag);
    }

    /**
     * A price that was deleted rather than changed reads as an em dash.
     *
     * It used to be proved on the flat document price, which the owner removed
     * from the product on 2026-09-27. The first slab makes the same point on a
     * field that still exists.
     */
    public function testABlankPriceReadsAsADashRatherThanZero(): void
    {
        $b = $this->seedArray();
        unset($b['destinations'][0]['rates']['express']['first']);

        $line = Diff::of(Seed::site(), $this->site($b))->lines[0];

        $this->assertSame('United Kingdom ' . self::MIDDOT . ' Express first slab', $line->label);
        $this->assertSame('PKR 4,500', $line->old);
        $this->assertSame(self::DASH, $line->new);
        $this->assertNull($line->flag, 'removing a price is not a percentage move');
    }

    /* ---------------------------------------------------- validate / warn */

    public function testTheSeedPublishesCleanAndQuietly(): void
    {
        $this->assertSame([], Validate::errors(Seed::site()));
        $this->assertSame([], Validate::warnings(Seed::site()));
        $this->assertSame([], Validate::errors(Seed::gridSite()));
    }

    public function testBrokenSettingsAndRatesAreRefusedInTheOwnersWords(): void
    {
        $b                                   = $this->seedArray();
        $b['company']['whatsapp']            = '123';
        $b['settings']['volumetricDivisor']  = 10;
        $b['settings']['addons']             = 'Bad line';
        $b['settings']['cutoffHour']         = 25;
        $b['destinations'][0]['rates']['express']['first'] = 0;
        $b['destinations'][1]['rates']       = [];
        $b['destinations'][2]['name']        = 'United Kingdom';

        $errs = Validate::errors($this->site($b));

        $this->assertTrue(in_array(
            'WhatsApp number must be 8' . self::NDASH . '15 digits with the country code, e.g. 923001234567.',
            $errs,
            true
        ));
        $this->assertTrue(in_array('Volumetric divisor must be between 1000 and 10000.', $errs, true));
        $this->assertTrue(in_array('Cutoff hour must be between 0 and 23, or blank.', $errs, true));
        $this->assertTrue(in_array(
            'United Kingdom ' . self::MIDDOT . ' Express: first-slab price must be greater than 0.',
            $errs,
            true
        ));
        $this->assertTrue(in_array('United States is shown on the site but has no prices.', $errs, true));
        $this->assertTrue(in_array("\u{201c}United Kingdom\u{201d} appears twice.", $errs, true));

        $optional = false;
        foreach ($errs as $e) {
            if (str_starts_with($e, 'Optional charge')) {
                $optional = true;
            }
        }
        $this->assertTrue($optional, 'a malformed add-on line is reported');
    }

    public function testAtLeastOneVisibleDestinationIsNeeded(): void
    {
        $b = $this->seedArray();
        foreach ($b['destinations'] as $i => $_) {
            $b['destinations'][$i]['active'] = false;
        }

        $this->assertTrue(in_array(
            'At least one destination must be shown on the site.',
            Validate::errors($this->site($b)),
            true
        ));
    }

    /**
     * A SITE WITH NO SERVICES CANNOT PRICE ANYTHING, and the validator is
     * where that is said - not the admin form's field list.
     *
     * The distinction matters. `Migrate::migrate()` answers an empty or
     * unreadable `services` key with the SEED's services, so this shape cannot
     * come through the editor; `SiteData::fromArray()` does not, so it can
     * come through a script, an import or anything else that builds the value
     * object straight from an array. A rule that is enforced by one of the two
     * doors is a rule the other door does not have.
     */
    public function testASiteWithNoServicesIsRefused(): void
    {
        $b             = $this->seedArray();
        $b['services'] = [];

        $errs = Validate::errors(SiteData::fromArray($b));

        $this->assertTrue(
            in_array('At least one service is needed.', $errs, true),
            'a document that prices nothing at all validated clean',
        );

        // And the seed, which has services, does not trip it.
        $this->assertFalse(in_array('At least one service is needed.', Validate::errors(Seed::site()), true));
    }

    public function testOddRelationshipsWarnWithoutBlocking(): void
    {
        $b                                                 = $this->seedArray();
        $b['destinations'][0]['rates']['express']['first'] = 3000; // cheaper than Normal's 3200
        $b['destinations'][0]['rates']['normal']['addl']   = 9000; // step above the first slab
        $b['destinations'][1]['rates']['express']['days']  = '';

        $site = $this->site($b);
        $w    = Validate::warnings($site);

        $this->assertTrue(in_array(
            'United Kingdom: Express is cheaper than Normal (PKR 3,000 vs PKR 3,200).',
            $w,
            true
        ));
        $this->assertTrue(in_array(
            'United States ' . self::MIDDOT . ' Express: no transit days, so no delivery date can be shown.',
            $w,
            true
        ));

        $step = false;
        foreach ($w as $line) {
            if (str_contains($line, 'per-step price (PKR 9,000) is higher')) {
                $step = true;
            }
        }
        $this->assertTrue($step);
        $this->assertSame([], Validate::errors($site), 'a warning never blocks a publish');
    }

    public function testMalformedHolidayLinesAreRejectedAndDatedOnesAccepted(): void
    {
        $b                         = $this->seedArray();
        $b['settings']['holidays'] = "2026-12-25 | Quaid-e-Azam Day\n25/12/2026\n";

        $errs = Validate::errors($this->site($b));
        $this->assertSame(1, count($errs));
        $this->assertStringContains('25/12/2026', $errs[0]);

        $b['settings']['holidays'] = "2026-12-25 | Quaid-e-Azam Day\n2027-03-23";
        $this->assertSame([], Validate::errors($this->site($b)));
    }

    /* ------------------------------------------------------------ helpers */

    /** @return list<string> */
    private function faqLines(): array
    {
        return explode("\n", Seed::content()['faq']);
    }

    /** The seed with only one destination's change kept, so a one-line summary can be asserted. */
    private function only(array $b, string $id): \Speedat\Domain\SiteDoc\SiteData
    {
        $doc = $this->seedArray();
        foreach ($b['destinations'] as $i => $dest) {
            if ($dest['id'] === $id) {
                $doc['destinations'][$i] = $dest;
            }
        }

        return $this->site($doc);
    }
}
