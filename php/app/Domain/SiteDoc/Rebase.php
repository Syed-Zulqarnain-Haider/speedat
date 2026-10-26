<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Carry a draft's edits onto a newer published version: a three-way merge of
 * stored documents.
 *
 * WHY THIS EXISTS. On 2026-10-11 version 18 was written straight into the
 * `versions` table, outside `Repo::publishVersion()`, so the draft was never
 * re-based and went on saying it was branched from 17. Every publish after
 * that was refused as a conflict, and the owner's draft - edited from 17 -
 * did not contain what 18 had changed (the Delivery add-on, the dates
 * switched off). Publishing it as it stood would have quietly undone 18.
 *
 * So a draft that is behind is not published as it is, and not thrown away.
 * Each field is decided by who changed it since the draft was branched:
 *
 *   - only the live version changed it  -> the live value (18's add-on stays)
 *   - only the draft changed it         -> the draft value (the owner's prices stay)
 *   - both changed it to the same thing -> that value
 *   - both changed it differently       -> the LIVE value, and the field is
 *                                          reported, so the owner decides
 *                                          with both values on screen
 *
 * Keeping the live value on a clash is deliberate: it is what customers see
 * now, so the merge can never put something on the site that nobody looked
 * at. The owner's version of the field is in the report, to be typed back if
 * it was the right one.
 *
 * Objects are merged key by key. Lists of objects that carry an `id`
 * (destinations, services) are merged item by item, so two people editing
 * different countries never clash; any other list (reviews, import layouts)
 * is one value. Pure: arrays in, arrays out, no database - the tests drive it
 * directly.
 */
final class Rebase
{
    /** Stands in for "this key or item is not there" on one side of the merge. */
    private const MISSING = "\0missing";

    /**
     * `path` is for people (countries by name), `keys` is for the program
     * (countries by id) so the editor can look the field up again later.
     *
     * @var list<array{path:string, keys:list<string>, live:mixed, draft:mixed, liveMissing:bool, draftMissing:bool}>
     */
    private array $conflicts = [];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $base  the version the draft was branched from
     * @param array<string,mixed> $live  the version customers see now
     * @param array<string,mixed> $draft the working copy
     * @return array{merged: array<string,mixed>, conflicts: list<array{path:string, keys:list<string>, live:mixed, draft:mixed, liveMissing:bool, draftMissing:bool}>}
     */
    public static function merge(array $base, array $live, array $draft): array
    {
        $run    = new self();
        $merged = $run->value($base, $live, $draft, [], []);

        return ['merged' => is_array($merged) ? $merged : $live, 'conflicts' => $run->conflicts];
    }

    /**
     * @param list<string> $path labels, for the report
     * @param list<string> $keys raw keys and ids, for finding the field again
     */
    private function value(mixed $base, mixed $live, mixed $draft, array $path, array $keys): mixed
    {
        if (self::same($draft, $base)) {
            return $live;
        }
        if (self::same($live, $base) || self::same($live, $draft)) {
            return $draft;
        }

        if (self::isIdList($base) && self::isIdList($live) && self::isIdList($draft)) {
            return $this->idList($base, $live, $draft, $path, $keys);
        }
        if (self::isObject($live) && self::isObject($draft)) {
            return $this->object(self::isObject($base) ? $base : [], $live, $draft, $path, $keys);
        }

        // A removal is kept as a flag, not as null: "version 18 removed this
        // country" and "version 18 blanked this box" are different news.
        $this->conflicts[] = [
            'path'         => implode(' > ', $path),
            'keys'         => $keys,
            'live'         => $live === self::MISSING ? null : $live,
            'draft'        => $draft === self::MISSING ? null : $draft,
            'liveMissing'  => $live === self::MISSING,
            'draftMissing' => $draft === self::MISSING,
        ];

        return $live;
    }

    /**
     * @param array<array-key,mixed> $base
     * @param array<array-key,mixed> $live
     * @param array<array-key,mixed> $draft
     * @param list<string>           $path
     * @param list<string>           $keys
     * @return array<array-key,mixed>
     */
    private function object(array $base, array $live, array $draft, array $path, array $keys): array
    {
        $out = [];

        // The live document's key order first, then anything only the draft
        // has, so the stored JSON keeps the shape the site already serves.
        foreach (array_keys($live + $draft) as $key) {
            $merged = $this->value(
                array_key_exists($key, $base) ? $base[$key] : self::MISSING,
                array_key_exists($key, $live) ? $live[$key] : self::MISSING,
                array_key_exists($key, $draft) ? $draft[$key] : self::MISSING,
                [...$path, (string) $key],
                [...$keys, (string) $key],
            );

            if ($merged !== self::MISSING) {
                $out[$key] = $merged;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $base
     * @param list<array<string,mixed>> $live
     * @param list<array<string,mixed>> $draft
     * @param list<string>              $path
     * @param list<string>              $keys
     * @return list<array<string,mixed>>
     */
    private function idList(array $base, array $live, array $draft, array $path, array $keys): array
    {
        $b = self::byId($base);
        $l = self::byId($live);
        $d = self::byId($draft);

        // Whose ORDER wins is decided like any other field: by who changed it.
        // "Reordered" is judged only on the items a side shares with the base,
        // so adding or removing a country is not mistaken for a re-sort (it
        // was, and a draft that added one country threw away the live order).
        $draftMoved = self::reordered(array_keys($b), array_keys($d));
        $liveMoved  = self::reordered(array_keys($b), array_keys($l));

        if ($draftMoved && $liveMoved && self::shared(array_keys($l), array_keys($d)) !== self::shared(array_keys($d), array_keys($l))) {
            $this->conflicts[] = [
                'path'         => implode(' > ', [...$path, '(order)']),
                'keys'         => [],
                'live'         => implode(', ', array_keys($l)),
                'draft'        => implode(', ', array_keys($d)),
                'liveMissing'  => false,
                'draftMissing' => false,
            ];
        }

        // Items only one side added go after it.
        $order = $draftMoved && !$liveMoved ? array_keys($d) : array_keys($l);
        foreach ([array_keys($l), array_keys($d)] as $ids) {
            foreach ($ids as $id) {
                if (!in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }

        $out = [];
        foreach ($order as $id) {
            $label  = (string) (($l[$id]['name'] ?? $d[$id]['name'] ?? $b[$id]['name'] ?? null) ?: $id);
            $merged = $this->value($b[$id] ?? self::MISSING, $l[$id] ?? self::MISSING, $d[$id] ?? self::MISSING, [...$path, $label], [...$keys, '#' . $id]);

            if ($merged !== self::MISSING) {
                $out[] = $merged;
            }
        }

        return $out;
    }

    /**
     * Did this side change the order of the items it shares with the base?
     *
     * @param list<string> $base
     * @param list<string> $side
     */
    private static function reordered(array $base, array $side): bool
    {
        return self::shared($base, $side) !== self::shared($side, $base);
    }

    /**
     * The ids of `$a` that are also in `$b`, in `$a`'s order.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function shared(array $a, array $b): array
    {
        return array_values(array_filter($a, static fn (string $id): bool => in_array($id, $b, true)));
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private static function byId(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[(string) $item['id']] = $item;
        }

        return $out;
    }

    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    private static function isIdList(mixed $v): bool
    {
        if (!is_array($v) || !array_is_list($v)) {
            return false;
        }
        // A repeated id is not a list of distinct items: merged by id, the
        // second row and its prices would vanish. Such a list is one value.
        $seen = [];
        foreach ($v as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_scalar($item['id'])) {
                return false;
            }
            $id = (string) $item['id'];
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
        }

        return true;
    }

    /**
     * Equal as stored JSON would be: 52500 and 52500.0 are one price, and key
     * order inside an object does not make two objects different.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::canon($a) === self::canon($b);
    }

    private static function canon(mixed $v): string
    {
        $norm = static function (mixed $x) use (&$norm): mixed {
            if (is_int($x) || is_float($x)) {
                return (float) $x;
            }
            if (is_array($x)) {
                $x = array_map($norm, $x);
                if (!array_is_list($x)) {
                    ksort($x, SORT_STRING);
                }
            }

            return $x;
        };

        return (string) json_encode($norm($v), JSON_PRESERVE_ZERO_FRACTION);
    }
}
