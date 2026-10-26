<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Core\Paths;
use Speedat\Domain\SiteDoc\Countries;
use Speedat\View\Copy;
use Speedat\View\Flag;
use Speedat\View\Phones;
use Speedat\View\Text;

/**
 * The sentences and the small parsers the pages read from, checked against the
 * TypeScript they were ported from.
 *
 * These are the functions a wrong price hides in: `fromPrice` is what a tile,
 * the headline and the rates board all print, so one bug here says three
 * different numbers on one page.
 */
final class CopyTest extends ViewTestCase
{
    public function testTheHeadlineNamesTheRouteFromTheRateDocument(): void
    {
        $this->assertSame('Lahore to Canada, Australia and Narnia.', Copy::heroTitleAuto($this->doc()));
    }

    public function testTheCountryListWordsItselfByLength(): void
    {
        $this->assertSame('', Copy::countryList([]));
        $this->assertSame('Canada', Copy::countryList(['Canada']));
        $this->assertSame('Canada and Germany', Copy::countryList(['Canada', 'Germany']));
        $this->assertSame(
            'Australia, Canada, France and Germany',
            Copy::countryList(['Australia', 'Canada', 'France', 'Germany'])
        );
        $this->assertSame(
            'A, B, C and 2 more countries',
            Copy::countryList(['A', 'B', 'C', 'D', 'E'])
        );
        // Four is still the flat form; "N more countries" starts at five,
        // which is why the singular branch of the TypeScript is unreachable.
        $this->assertSame('A, B, C and D', Copy::countryList(['A', 'B', 'C', 'D']));
    }

    public function testTheHeroLineIsTheLowestOneKilogramPriceAndTheDaySpan(): void
    {
        // Australia, Economy, 1 kg = 3,900 + the 500 pickup add-on.
        $this->assertSame('1 kg from PKR 4,400 · 3 to 12 days', Copy::heroSubAuto($this->doc()));
    }

    public function testTheFromPriceIsTheCheapestServiceWithTheDefaultAddons(): void
    {
        $doc  = $this->doc();
        $from = Copy::fromPrice($doc, 'ca');

        $this->assertNotNull($from);
        $this->assertSame(4600.0, $from?->total);
        $this->assertSame('eco', $from?->serviceId);
        $this->assertSame("8\u{2013}12", $from?->days);
    }

    public function testAnUnpricedOrHiddenDestinationHasNoFromPrice(): void
    {
        $doc = $this->doc();

        // Inactive: priceAll refuses it, so there is nothing to print.
        $this->assertNull(Copy::fromPrice($doc, 'de'));
        $this->assertNull(Copy::fromPrice($doc, 'nope'));
    }

    /**
     * There is no document price to compute any more (owner, 2026-09-27), so the
     * two helpers that computed one are gone. This pins their absence: a helper
     * that quietly came back would put a flat rate on the board again.
     */
    public function testTheDocumentPriceHelpersAreGoneRatherThanUnused(): void
    {
        $this->assertFalse(method_exists(Copy::class, 'docPrice'), 'Copy::docPrice() came back');
        $this->assertFalse(method_exists(Copy::class, 'hasDocRate'), 'Copy::hasDocRate() came back');
    }

    public function testTheDaysTextCollapsesASingleDayRange(): void
    {
        $this->assertSame("4\u{2013}6", Copy::daysText('4-6'));
        $this->assertSame("3\u{2013}5", Copy::daysText('3 to 5 days'));
        $this->assertSame('4', Copy::daysText('4'));
        $this->assertSame('', Copy::daysText(''));
        $this->assertSame('', Copy::daysText(null));
    }

    public function testTheBoardCaptionSaysWhatThePricesIncludeAndWhatTheyAreFor(): void
    {
        $note = Copy::boardNote($this->doc());

        $this->assertStringContains('kg parcel', $note);
        // Named by the owner's add-on label ("Pickup and service charges" in
        // this fixture), not a hard-coded "pickup".
        $this->assertStringContains('pickup and service charges PKR', $note);
        // The hard space keeps "1 kg" whole where the line wraps.
        $this->assertStringContains("1\u{00A0}kg", $note);

        // The document clause left with the document rate (owner, 2026-09-27):
        // the board has no Documents column for it to explain.
        $this->assertStringNotContains('document', $note);
    }

    public function testTheBoardCaptionSaysSomethingElseEntirelyUnderHold(): void
    {
        $note = Copy::boardNote($this->doc([], ['on' => true]));

        $this->assertSame('Working days per service · prices are being updated', $note);
        $this->assertStringNotContains('PKR', $note);
    }

    /* ------------------------------------------------------------- Text */

    public function testTheOriginCitiesSplitOnEverySeparatorTheOwnerMightType(): void
    {
        $this->assertSame(['Lahore'], Text::originCities('Lahore'));
        $this->assertSame(['Lahore', 'Karachi'], Text::originCities('Lahore, Karachi'));
        $this->assertSame(['Lahore', 'Karachi'], Text::originCities("Lahore\nKarachi"));
        $this->assertSame(['Lahore', 'Karachi'], Text::originCities('Lahore;  Karachi '));
        $this->assertSame([], Text::originCities('  '));
    }

    public function testTheCutoffSentenceMatchesWhatTheCalculatorActuallyDoes(): void
    {
        $this->assertSame('Book before 3 pm for same-day pickup', Text::cutoffFact(15.0));
        // At 0 the calculator's own test is true at every hour, so promising a
        // same-day pickup would be a promise it never keeps.
        $this->assertSame('No same-day pickup; we collect on the next working day', Text::cutoffFact(0.0));
        $this->assertSame('', Text::cutoffFact(null));
    }

    public function testTheMapLinkCannotBeTurnedIntoAnExtraParameter(): void
    {
        $href = Text::mapHref('', 'Shop 4 & 5, Main Blvd');

        $this->assertStringContains('query=Shop%204%20%26%205', $href);
        $this->assertSame('', Text::mapHref('', ''));
        $this->assertSame('https://maps.example/x', Text::mapHref('https://maps.example/x', 'ignored'));
    }

    /* -------------------------------------------------------- Countries */

    public function testTheFlagCodeFollowsTheNameThenTheIdThenGivesUp(): void
    {
        $this->assertSame('ca', Countries::flagCode('Canada'));
        $this->assertSame('gb', Countries::flagCode('UK'));
        $this->assertSame('gb', Countries::flagCode('the United Kingdom'));
        $this->assertSame('tr', Countries::flagCode('Türkiye'));
        $this->assertSame('ae', Countries::flagCode('Dubai'));
        // The id, only when it is exactly a code the list knows.
        $this->assertSame('fr', Countries::flagCode('Somewhere', 'fr'));
        $this->assertNull(Countries::flagCode('Narnia', 'zz'));
        $this->assertNull(Countries::flagCode('Narnia'));
    }

    public function testTheBadgeIsTheFirstTwoLettersUpperCased(): void
    {
        $this->assertSame('NA', Flag::badgeFor('Narnia'));
        $this->assertSame('NE', Flag::badgeFor('New Erewhon'));
        $this->assertSame('', Flag::badgeFor('123'));
    }

    /** Every code in the list must have a file, or a real country silently shows a badge. */
    public function testEveryCodeInTheListHasAFlagFile(): void
    {
        $dir = Paths::publicDir() . '/assets/flags';
        if (!is_dir($dir)) {
            $this->skip('public/assets/flags is not present in this checkout');
        }

        $missing = [];
        foreach (['gb', 'us', 'ca', 'au', 'ae', 'sa', 'de', 'fr', 'tr', 'pk', 'kr', 'za', 'mu', 'az', 'ar'] as $code) {
            if (!is_file($dir . '/' . $code . '.svg')) {
                $missing[] = $code;
            }
        }

        $this->assertSame([], $missing, 'flag files are missing');
    }

    /* ----------------------------------------------------------- Phones */

    public function testEveryNumberInAnOwnerTypedLineBecomesALink(): void
    {
        $segments = Phones::segments('Mobile: +92 333 766 7076 · +92 334 77 33 328');

        $this->assertSame(4, count($segments));
        $this->assertSame('Mobile: ', $segments[0]->text);
        $this->assertSame('tel:+923337667076', $segments[1]->tel);
        $this->assertSame('tel:+923347733328', $segments[3]->tel);
    }

    public function testShortRunsOfDigitsStayText(): void
    {
        $segments = Phones::segments('Shop 4, open 9 am - 7 pm');

        $this->assertSame(1, count($segments));
        $this->assertFalse($segments[0]->isPhone());
    }

    public function testTwoSpellingsOfOneNumberCompareEqual(): void
    {
        $this->assertTrue(Phones::same('+92 333 766 7076', '0333 766 7076'));
        $this->assertTrue(Phones::same('923337667076', '333 7667076'));
        $this->assertFalse(Phones::same('+92 333 766 7076', '+92 334 77 33 328'));
        $this->assertFalse(Phones::same('12345', '12345'));
    }

    public function testTheAlsoRowDropsTheNumbersThePageAlreadyStated(): void
    {
        // The whole item goes, label and all, when its only number is stated.
        $this->assertSame([], Phones::segmentsExcept('Mobile: +92 315 766 7076', ['923157667076']));

        // One of two goes, and the word that joined them goes with it.
        $kept = Phones::segmentsExcept('Mobile: +92 315 766 7076 or +92 334 77 33 328', ['923157667076']);
        $text = '';
        foreach ($kept as $segment) {
            $text .= $segment->text;
        }
        $this->assertStringContains('334', $text);
        $this->assertStringNotContains('315', $text);

        // Nothing to drop: the line comes back exactly as segments() reads it.
        $this->assertSame(
            count(Phones::segments('Mobile: +92 334 77 33 328')),
            count(Phones::segmentsExcept('Mobile: +92 334 77 33 328', ['923157667076']))
        );
    }
}
