<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * `content.address` - one free-text line - as a schema.org `PostalAddress`.
 *
 * The owner types an address the way an address is written on an envelope:
 * "Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model
 * Town, Lahore". Structured data wants it in parts.
 *
 * The split is a DERIVATION, never a guess. Only two things are ever moved out
 * of the street line: a trailing segment that is one of the pickup cities the
 * owner already listed in `company.originCities`, and a trailing segment that
 * is `company.origin`. Anything else stays exactly where it was typed, in
 * `streetAddress`, which is a legal PostalAddress on its own. Nothing is
 * looked up, inferred from a postcode, or invented; if the owner's last
 * segment is not a city he listed, the locality is simply not claimed.
 */
final class Address
{
    /**
     * @param  list<string>        $cities  from company.originCities
     * @param  string              $country company.origin
     * @return array<string,mixed> a PostalAddress with the empty keys left out; [] when there is no address at all
     */
    public static function postal(string $address, array $cities, string $country): array
    {
        $segments = [];
        foreach (explode(',', $address) as $segment) {
            $trimmed = trim($segment);
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }

        if ($segments === []) {
            return [];
        }

        $countryName = trim($country);
        $locality    = '';

        // A trailing country, then a trailing city. In that order, because an
        // envelope is written that way: "..., Lahore, Pakistan".
        if ($countryName !== '' && count($segments) > 1 && self::same(end($segments), $countryName)) {
            array_pop($segments);
        }

        if (count($segments) > 1) {
            $last = (string) end($segments);
            foreach ($cities as $city) {
                if (self::same($last, $city)) {
                    $locality = $last;
                    array_pop($segments);
                    break;
                }
            }
        }

        $out = ['@type' => 'PostalAddress', 'streetAddress' => implode(', ', $segments)];

        if ($locality !== '') {
            $out['addressLocality'] = $locality;
        }
        if ($countryName !== '') {
            $out['addressCountry'] = $countryName;
        }

        return $out;
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
