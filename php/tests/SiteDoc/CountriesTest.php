<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Str;
use Speedat\Domain\SiteDoc\Countries;
use Speedat\Tests\TestCase;

/**
 * The flag lookup. Same cases as src/lib/site/countries.test.ts, plus the
 * intl-free path: the production host may not have the extension, and a
 * "Türkiye" that folds to "t rkiye" there would show no flag on the one row the
 * owner checks first.
 */
final class CountriesTest extends TestCase
{
    public function testNormalizeStripsAccentsCasePunctuationAndALeadingThe(): void
    {
        $this->assertSame('turkiye', Countries::normalize("T\u{00fc}rkiye"));
        $this->assertSame('u k', Countries::normalize('U.K.'));
        $this->assertSame('netherlands', Countries::normalize('  The Netherlands '));
        $this->assertSame('bosnia herzegovina', Countries::normalize('Bosnia & Herzegovina'));
    }

    public function testFlagCodeMatchesFullNamesAndTheirAliases(): void
    {
        $this->assertSame('gb', Countries::flagCode('United Kingdom'));
        $this->assertSame('gb', Countries::flagCode('UK'));
        $this->assertSame('gb', Countries::flagCode('U.K.'));
        $this->assertSame('gb', Countries::flagCode('Britain'));
        $this->assertSame('tr', Countries::flagCode("T\u{00fc}rkiye"));
        $this->assertSame('tr', Countries::flagCode('Turkey'));
        $this->assertSame('ae', Countries::flagCode('Dubai'));
        $this->assertSame('ae', Countries::flagCode('United Arab Emirates'));
        $this->assertSame('us', Countries::flagCode('USA'));
        $this->assertSame('us', Countries::flagCode('United States'));
        $this->assertSame('us', Countries::flagCode('America'));
        $this->assertSame('sa', Countries::flagCode('KSA'));
        $this->assertSame('sa', Countries::flagCode('Saudi'));
        $this->assertSame('nl', Countries::flagCode('Holland'));
    }

    public function testFlagCodeFallsBackToATwoLetterIdOnlyWhenItIsAKnownCode(): void
    {
        $this->assertNull(Countries::flagCode('Atlantis'));
        $this->assertSame('fr', Countries::flagCode('Somewhere', 'fr'));
        $this->assertSame('fr', Countries::flagCode('Somewhere', 'FR'));
        $this->assertNull(Countries::flagCode('Somewhere', 'france'));
        $this->assertNull(Countries::flagCode('Somewhere', 'zz'));
        $this->assertSame('fr', Countries::flagCode('France', 'france'));
    }

    public function testTheNamePreferredOverTheId(): void
    {
        $this->assertSame('de', Countries::flagCode('Germany', 'au'));
    }

    public function testAccentedNamesStillMatchWithoutTheIntlExtension(): void
    {
        // The alias table itself is ASCII, so only the incoming name has to fold
        // the same way with and without the extension.
        Str::withoutIntl(function (): void {
            $this->assertSame('turkiye', Countries::normalize("T\u{00fc}rkiye"));
            $this->assertSame('tr', Countries::flagCode("T\u{00fc}rkiye"));
            $this->assertSame('espana', Countries::normalize("Espa\u{00f1}a"));
            $this->assertSame('es', Countries::flagCode("Espa\u{00f1}a"));
        });
    }

    public function testEveryAliasResolvesToACodeThatIsInTheList(): void
    {
        $known = Countries::knownCodes();

        foreach (Countries::codes() as $alias => $code) {
            $this->assertTrue(in_array($code, $known, true), $alias . ' maps to an unknown code ' . $code);
        }

        // Ten flags the seed's own destinations need; a missing one is a blank badge on the rates board.
        foreach (['gb', 'us', 'ca', 'au', 'ae', 'sa', 'de', 'it', 'tr', 'my'] as $code) {
            $this->assertTrue(in_array($code, $known, true), $code . ' is missing from the code list');
        }
    }
}
