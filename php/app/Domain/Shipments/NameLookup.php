<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

use Speedat\Core\Db;

/**
 * Turning a stored `dest_id` or `service_id` back into the name a human reads.
 * Port of `namesFor` in src/lib/site/repo.ts.
 *
 * THE BUG THIS EXISTS TO PREVENT. Shipments and leads store ids. When the owner
 * takes a country off the rate card - an airline stops flying there, a sheet
 * arrives without it - every record booked under it loses its name, and the
 * Next.js admin printed the raw id instead: a customer whose parcel was going
 * to the United Kingdom got a WhatsApp update saying their shipment was on its
 * way to "gb". The rate card is a snapshot per publish and every snapshot is
 * kept, so the name is not lost, only moved: the answer is in the most recent
 * published version that still carried the id.
 *
 * So the lookup is, in order:
 *   1. the live document (the highest version), which answers almost always;
 *   2. the newest older version that still had the id;
 *   3. nothing - and the caller falls back to the id, because printing "gb" is
 *      better than printing an empty cell, and an id no version ever knew is a
 *      record that was written by hand or by something broken.
 *
 * Results are memoised per instance. One admin page listing two hundred
 * shipments to a dozen countries asks about a dozen ids, not two hundred.
 *
 * INTERFACE NOTE: this reads the `versions` table directly because the site
 * document repository is not part of this package. When that repository lands
 * it should own the query and this class should call it; the behaviour above is
 * the contract either way.
 */
final class NameLookup
{
    public const DESTINATIONS = 'destinations';
    public const SERVICES     = 'services';

    /** How many candidate versions to open before giving up on an id. */
    private const MAX_CANDIDATES = 3;

    /** field => id => name, including the misses, which are stored as ''. */
    private array $cache = [self::DESTINATIONS => [], self::SERVICES => []];

    /** field => id => name, from the highest version; null until loaded. */
    private array $live = [self::DESTINATIONS => null, self::SERVICES => null];

    /**
     * @param  iterable<string>    $ids
     * @return array<string,string> id => name, for the ids that have one
     */
    public function destinations(iterable $ids): array
    {
        return $this->names(self::DESTINATIONS, $ids);
    }

    /**
     * @param  iterable<string>    $ids
     * @return array<string,string>
     */
    public function services(iterable $ids): array
    {
        return $this->names(self::SERVICES, $ids);
    }

    /**
     * One name, falling back to the id itself.
     *
     * This is the method display code should use: it can never return an empty
     * string, so no caller has to remember the fallback and forget it once.
     */
    public function nameOr(string $field, string $id): string
    {
        if ($id === '') {
            return '';
        }

        $found = $this->names($field, [$id]);

        return $found[$id] ?? $id;
    }

    public function destinationName(string $id): string
    {
        return $this->nameOr(self::DESTINATIONS, $id);
    }

    public function serviceName(string $id): string
    {
        return $this->nameOr(self::SERVICES, $id);
    }

    /** Drop everything remembered. A publish changes the answers. */
    public function forget(): void
    {
        $this->cache = [self::DESTINATIONS => [], self::SERVICES => []];
        $this->live  = [self::DESTINATIONS => null, self::SERVICES => null];
    }

    /**
     * @param  iterable<string>     $ids
     * @return array<string,string>
     */
    private function names(string $field, iterable $ids): array
    {
        $field = self::field($field);
        $live  = $this->liveNames($field);
        $out   = [];

        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '' || isset($out[$id])) {
                continue;
            }

            if (isset($live[$id])) {
                $out[$id] = $live[$id];
                continue;
            }

            if (!array_key_exists($id, $this->cache[$field])) {
                $this->cache[$field][$id] = $this->retiredName($field, $id) ?? '';
            }

            if ($this->cache[$field][$id] !== '') {
                $out[$id] = $this->cache[$field][$id];
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function liveNames(string $field): array
    {
        if ($this->live[$field] !== null) {
            return $this->live[$field];
        }

        // Only the subtree, not the whole document: a rate card with two hundred
        // destinations and a price per kilogram in each is not something to pull
        // into PHP to read two fields off it.
        $raw = Db::value(
            'SELECT JSON_EXTRACT(data, ?) FROM versions ORDER BY version DESC LIMIT 1',
            ['$.' . $field],
        );

        return $this->live[$field] = self::pairs($raw);
    }

    /**
     * The name an id last had, from the most recent version that still carried
     * it; null when no version ever did.
     *
     * JSON_SEARCH does the narrowing and PHP does the deciding. The search
     * string is a LIKE pattern - `%` and `_` are wildcards in it - so those are
     * escaped, and then the candidate rows are opened and checked for the exact
     * id anyway. An id that got through the escaping and matched something else
     * simply fails the PHP check and the next candidate is tried.
     */
    private function retiredName(string $field, string $id): ?string
    {
        $rows = Db::all(
            'SELECT JSON_EXTRACT(data, :subtree) AS items
               FROM versions
              WHERE JSON_SEARCH(data, \'one\', :needle, :escape, :path) IS NOT NULL
              ORDER BY version DESC
              LIMIT ' . self::MAX_CANDIDATES,
            [
                ':subtree' => '$.' . $field,
                ':needle'  => self::escapeLike($id),
                ':escape'  => '\\',
                ':path'    => '$.' . $field . '[*].id',
            ],
        );

        foreach ($rows as $row) {
            $pairs = self::pairs($row['items'] ?? null);
            if (isset($pairs[$id]) && $pairs[$id] !== '') {
                return $pairs[$id];
            }
        }

        return null;
    }

    /**
     * `[{id, name, ...}, ...]` as JSON becomes `id => name`.
     *
     * @return array<string,string>
     */
    private static function pairs(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id   = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
            $name = isset($item['name']) && is_scalar($item['name']) ? (string) $item['name'] : '';
            if ($id !== '' && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    /** The two LIKE wildcards, and the escape character itself. */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private static function field(string $field): string
    {
        if ($field !== self::DESTINATIONS && $field !== self::SERVICES) {
            throw new \InvalidArgumentException('Only destinations and services have names to look up.');
        }

        return $field;
    }
}
