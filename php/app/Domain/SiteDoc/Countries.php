<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Str;

/**
 * Country name -> ISO 3166-1 alpha-2 code, so a destination can show its flag
 * (public/flags/<code>.svg) beside its name. Port of src/lib/site/countries.ts.
 *
 * Names are matched after normalising accents, case, punctuation and a leading
 * "the", with the aliases people actually type (UK, USA, Dubai, KSA...). A
 * destination id that is itself a known two-letter code is the fallback;
 * anything else is null and the view prints a two-letter badge instead.
 *
 * Pure: no database, no configuration, no I/O.
 */
final class Countries
{
    /**
     * `[code, ...names and aliases]`; every code has a file in public/flags/.
     *
     * @var list<list<string>>
     */
    private const LIST = [
        ['gb', 'united kingdom', 'uk', 'u k', 'britain', 'great britain', 'england', 'scotland', 'wales', 'northern ireland', 'london'],
        ['us', 'united states', 'usa', 'u s a', 'u s', 'america', 'united states of america', 'states'],
        ['ca', 'canada'],
        ['au', 'australia'],
        ['nz', 'new zealand'],
        ['ae', 'united arab emirates', 'uae', 'u a e', 'emirates', 'dubai', 'abu dhabi', 'sharjah'],
        ['sa', 'saudi arabia', 'ksa', 'saudi', 'kingdom of saudi arabia'],
        ['qa', 'qatar', 'doha'],
        ['om', 'oman', 'muscat'],
        ['kw', 'kuwait'],
        ['bh', 'bahrain'],
        ['de', 'germany', 'deutschland'],
        ['fr', 'france'],
        ['it', 'italy', 'italia'],
        ['es', 'spain', 'espana'],
        ['pt', 'portugal'],
        ['nl', 'netherlands', 'holland'],
        ['be', 'belgium'],
        ['lu', 'luxembourg'],
        ['ch', 'switzerland'],
        ['at', 'austria'],
        ['ie', 'ireland', 'republic of ireland'],
        ['se', 'sweden'],
        ['no', 'norway'],
        ['dk', 'denmark'],
        ['fi', 'finland'],
        ['pl', 'poland'],
        ['cz', 'czechia', 'czech republic'],
        ['hu', 'hungary'],
        ['ro', 'romania'],
        ['gr', 'greece'],
        ['cy', 'cyprus'],
        ['mt', 'malta'],
        ['tr', 'turkiye', 'turkey'],
        ['ru', 'russia', 'russian federation'],
        ['ua', 'ukraine'],
        ['my', 'malaysia', 'kuala lumpur'],
        ['sg', 'singapore'],
        ['th', 'thailand', 'bangkok'],
        ['id', 'indonesia'],
        ['vn', 'vietnam', 'viet nam'],
        ['ph', 'philippines'],
        ['cn', 'china'],
        ['hk', 'hong kong'],
        ['tw', 'taiwan'],
        ['jp', 'japan'],
        ['kr', 'south korea', 'korea', 'republic of korea'],
        ['in', 'india'],
        ['bd', 'bangladesh'],
        ['lk', 'sri lanka'],
        ['np', 'nepal'],
        ['mv', 'maldives'],
        ['af', 'afghanistan'],
        ['ir', 'iran'],
        ['iq', 'iraq'],
        ['jo', 'jordan'],
        ['lb', 'lebanon'],
        ['eg', 'egypt'],
        ['ma', 'morocco'],
        ['tn', 'tunisia'],
        ['za', 'south africa'],
        ['ke', 'kenya'],
        ['ng', 'nigeria'],
        ['mu', 'mauritius'],
        ['uz', 'uzbekistan'],
        ['kz', 'kazakhstan'],
        ['az', 'azerbaijan'],
        ['br', 'brazil'],
        ['mx', 'mexico'],
        ['ar', 'argentina'],
        ['pk', 'pakistan'],
    ];

    /**
     * NFD -> strip combining marks -> lowercase -> punctuation to spaces ->
     * collapse spaces -> drop a leading "the ".
     *
     * Unlike Str::nameKey() this keeps non-ASCII letters, because the alias
     * table is matched on words, not on an ASCII key.
     */
    public static function normalize(string $s): string
    {
        $out = Str::stripMarks($s);
        $out = mb_strtolower($out, 'UTF-8');
        $out = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $out) ?? '';
        $out = trim($out);

        return preg_replace('/^the /', '', $out) ?? $out;
    }

    /**
     * Normalised name or alias -> lowercase ISO code.
     *
     * @return array<string,string>
     */
    public static function codes(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::LIST as $row) {
                $code = $row[0];
                foreach (array_slice($row, 1) as $name) {
                    $map[self::normalize($name)] = $code;
                }
            }
        }

        return $map;
    }

    /**
     * The flag code for a destination: by name (with aliases), else by its id
     * when that is exactly a known two-letter code, else null.
     */
    public static function flagCode(string $name, ?string $id = null): ?string
    {
        $byName = self::codes()[self::normalize($name)] ?? null;
        if ($byName !== null) {
            return $byName;
        }

        if ($id !== null && preg_match('/^[A-Za-z]{2}$/', $id) === 1) {
            $lower = strtolower($id);
            if (in_array($lower, self::knownCodes(), true)) {
                return $lower;
            }
        }

        return null;
    }

    /** Every code the table can return. @return list<string> */
    public static function knownCodes(): array
    {
        static $codes = null;

        if ($codes === null) {
            $codes = [];
            foreach (self::LIST as $row) {
                $codes[] = $row[0];
            }
        }

        return $codes;
    }
}
