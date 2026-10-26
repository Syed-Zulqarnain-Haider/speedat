<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Num;

/**
 * What `Repo::catchUpDraft()` did to a draft that had fallen behind, kept in
 * app_settings until the next publish, discard or restore so the editor can
 * say it on every visit and not only on the one request that happened to do
 * it.
 *
 * `conflicts` are the fields both the draft and the newer live version had
 * changed, each with both values: the merge kept the live one, and the owner
 * is shown the one they typed so it can be put back if it was the right one.
 * Each carries `keys` (countries and services by id) as well as the readable
 * `path`, so the editor can look the field up again and stop listing it once
 * the owner has typed their value back.
 */
final class RebaseNote
{
    /** Shown on screen at most this many; the rest are counted. */
    public const SHOWN = 20;

    /**
     * @param list<array{path:string, keys:list<string>, live:mixed, draft:mixed, liveMissing:bool, draftMissing:bool}> $conflicts
     * @param array<string,string> $services service id => name, for the labels
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly int $carried,
        public readonly array $conflicts,
        public readonly string $at,
        public readonly string $currency = 'PKR',
        public readonly array $services = [],
        /** When the newer version went live (not when the draft was caught up, which is `at`). */
        public readonly string $liveAt = '',
    ) {
    }

    /**
     * A second catch-up before anybody published: the draft has now been moved
     * twice, so the note keeps the FIRST starting version and every clash from
     * both moves. A field that clashed both times keeps the newest live value
     * and the value the owner originally typed - the one the first move took
     * away from them.
     */
    public function followedBy(self $next): self
    {
        $byPath = [];
        foreach ($this->conflicts as $c) {
            $byPath[self::keyOf($c)] = $c;
        }
        foreach ($next->conflicts as $c) {
            $k = self::keyOf($c);
            if (isset($byPath[$k])) {
                $c['draft']        = $byPath[$k]['draft'];
                $c['draftMissing'] = $byPath[$k]['draftMissing'];
            }
            $byPath[$k] = $c;
        }

        return new self($this->from, $next->to, $next->carried, array_values($byPath), $next->at, $next->currency, $next->services + $this->services, $next->liveAt);
    }

    /** Nothing worth a box on the screen: no edits carried and nothing clashed. */
    public function isEmpty(): bool
    {
        return $this->carried === 0 && $this->conflicts === [];
    }

    /**
     * The paragraph the editor leads with. Short sentences, the count kept
     * away from the version number, and true about the clashes: `$open` is how
     * many are still waiting (`openLines()`), so the box never says "all your
     * edits are here" while some were replaced, nor "listed below" once the
     * owner has dealt with every one.
     */
    public function sentence(?int $open = null): string
    {
        $open ??= count($this->conflicts);
        $when = $this->liveAt === '' ? '' : Format::fmtDateTime($this->liveAt);
        $out  = 'The live website was changed' . ($when === '' ? '' : ' on ' . $when) . ' (version ' . $this->to
            . '), after you started this draft. Your draft now includes that change.';

        if ($open > 0) {
            $out .= ' ' . $open . ' of your edits ' . ($open === 1 ? 'was' : 'were') . ' replaced, because that version'
                . ' changed the same thing - listed below; check each before you publish.';
            $out .= $this->carried > 0 ? ' Your other edits are still here.' : '';
        } elseif ($this->conflicts !== []) {
            $out .= ' You have dealt with every item that clashed. You can publish when ready.';
        } elseif ($this->carried > 0) {
            $out .= ' All your own edits are still here. Publishing adds them without removing that change.';
        }

        return $out;
    }

    /**
     * The clashes still waiting for the owner, as readable lines. A clash whose
     * field the owner has since changed in the draft is no longer listed - they
     * have made their decision.
     *
     * @param array<string,mixed>|null $draft the draft as it is now (toArray), or null to list every clash
     * @param array<string,mixed>|null $live  the live document (toArray)
     * @return list<string>
     */
    public function openLines(?array $draft = null, ?array $live = null): array
    {
        $out = [];
        foreach ($this->openConflicts($draft, $live) as $c) {
            $out[] = $this->line($c);
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $draft
     * @param array<string,mixed>|null $live
     * @return list<array{path:string, keys:list<string>, live:mixed, draft:mixed, liveMissing:bool, draftMissing:bool}>
     */
    public function openConflicts(?array $draft = null, ?array $live = null): array
    {
        if ($draft === null || $live === null) {
            return $this->conflicts;
        }

        return array_values(array_filter(
            $this->conflicts,
            static fn (array $c): bool => $c['keys'] === []
                || self::canon(self::at($draft, $c['keys'])) === self::canon(self::at($live, $c['keys'])),
        ));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'from'      => $this->from,
            'to'        => $this->to,
            'carried'   => $this->carried,
            'conflicts' => $this->conflicts,
            'at'        => $this->at,
            'currency'  => $this->currency,
            'services'  => $this->services,
            'liveAt'    => $this->liveAt,
        ];
    }

    /**
     * Forgiving: a damaged row reads as "nothing to say" rather than an error
     * page on every visit to the editor, so every field is checked before it
     * is cast.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_numeric($raw['from'] ?? null) || !is_numeric($raw['to'] ?? null)) {
            return null;
        }

        $conflicts = [];
        foreach (is_array($raw['conflicts'] ?? null) ? $raw['conflicts'] : [] as $c) {
            if (!is_array($c) || !is_scalar($c['path'] ?? null)) {
                continue;
            }
            $keys = [];
            foreach (is_array($c['keys'] ?? null) ? $c['keys'] : [] as $k) {
                if (is_scalar($k)) {
                    $keys[] = (string) $k;
                }
            }
            $conflicts[] = [
                'path'         => (string) $c['path'],
                'keys'         => $keys,
                'live'         => $c['live'] ?? null,
                'draft'        => $c['draft'] ?? null,
                'liveMissing'  => ($c['liveMissing'] ?? false) === true,
                'draftMissing' => ($c['draftMissing'] ?? false) === true,
            ];
        }

        $services = [];
        foreach (is_array($raw['services'] ?? null) ? $raw['services'] : [] as $id => $name) {
            if (is_scalar($name)) {
                $services[(string) $id] = (string) $name;
            }
        }

        return new self(
            (int) $raw['from'],
            (int) $raw['to'],
            is_numeric($raw['carried'] ?? null) ? (int) $raw['carried'] : 0,
            $conflicts,
            is_scalar($raw['at'] ?? null) ? (string) $raw['at'] : '',
            is_scalar($raw['currency'] ?? null) && $raw['currency'] !== '' ? (string) $raw['currency'] : 'PKR',
            $services,
            is_scalar($raw['liveAt'] ?? null) ? (string) $raw['liveAt'] : '',
        );
    }

    /* ------------------------------------------------------------ internals */

    /** @param array{path:string, keys:list<string>, live:mixed, draft:mixed, liveMissing:bool, draftMissing:bool} $c */
    private function line(array $c): string
    {
        [$label, $money] = $this->label($c);
        $v = 'version ' . $this->to;

        if ($c['liveMissing']) {
            return $label . ': ' . $v . ' removed it, so it stays removed - your draft had ' . $this->show($c['draft'], $money)
                . '. Add it again only if it should be on the website.';
        }
        if ($c['draftMissing']) {
            return $label . ': your draft removed it, but ' . $v . ' changed it, so it was kept as ' . $this->show($c['live'], $money)
                . '. Delete it again if you still want it gone.';
        }

        return $label . ': kept ' . $this->show($c['live'], $money) . ' from ' . $v . ' - your draft had ' . $this->show($c['draft'], $money);
    }

    /**
     * "United Kingdom · Express 14 kg" - the editor's own words for a field,
     * the same ones `Diff` uses, built from the stored keys and labels.
     *
     * @param array{path:string, keys:list<string>} $c
     * @return array{string, bool} the label, and whether the value is an amount of money
     */
    private function label(array $c): array
    {
        $keys   = $c['keys'];
        $labels = explode(' > ', $c['path']);

        if (($keys[0] ?? '') === 'destinations' && isset($keys[1])) {
            $country = $labels[1] ?? ltrim($keys[1], '#');
            $svc     = isset($keys[3]) ? ($this->services[$keys[3]] ?? ucfirst($keys[3])) : '';
            $field   = $keys[4] ?? null;

            if (($keys[2] ?? '') === 'rates' && $svc !== '') {
                return match (true) {
                    $field === 'first'                => [$country . " \u{00b7} " . $svc . ' first slab', true],
                    $field === 'addl'                 => [$country . " \u{00b7} " . $svc . ' per step', true],
                    $field === 'days'                 => [$country . " \u{00b7} " . $svc . ' days', false],
                    $field === 'grid' && isset($keys[5]) => [$country . " \u{00b7} " . $svc . ' ' . $keys[5] . ' kg', true],
                    default                           => [$country . " \u{00b7} " . $svc . ' prices', false],
                };
            }
            if (($keys[2] ?? '') === 'active') {
                return [$country . " \u{00b7} shown on the website", false];
            }

            return [count($keys) === 2 ? $country : $country . " \u{00b7} " . implode(' ', array_slice($labels, 2)), false];
        }

        $area = match ($keys[0] ?? '') {
            'settings' => 'Settings',
            'company'  => 'Company',
            'content'  => 'Website text',
            'promo'    => 'Popup',
            'reviews'  => 'Reviews',
            'services' => 'Services',
            default    => ucfirst($keys[0] ?? $c['path']),
        };

        return [count($labels) > 1 ? $area . " \u{00b7} " . implode(" \u{00b7} ", array_slice($labels, 1)) : $area, false];
    }

    private function show(mixed $v, bool $money): string
    {
        if ($v === null || $v === '') {
            return '(blank)';
        }
        if (is_bool($v)) {
            return $v ? 'switched on' : 'switched off';
        }
        if ($money && is_numeric($v)) {
            return Money::fmtMoney(Num::jsNumber($v), $this->currency);
        }
        if (is_scalar($v)) {
            return '"' . mb_strimwidth((string) $v, 0, 80, '...') . '"';
        }

        // A list of named items (countries, services, reviews): how many and
        // which, so the owner can see what it held without a wall of numbers.
        if (array_is_list($v)) {
            $names = [];
            foreach ($v as $item) {
                if (is_array($item)) {
                    $n = $item['name'] ?? $item['author'] ?? $item['id'] ?? null;
                    if (is_scalar($n) && (string) $n !== '') {
                        $names[] = (string) $n;
                    }
                }
            }
            if ($names !== []) {
                return count($v) . ' item' . (count($v) === 1 ? '' : 's') . ': "' . mb_strimwidth(implode(', ', $names), 0, 120, '...') . '"';
            }
        }

        // An object (a whole country): its plain values, capped, so the owner
        // can still see and retype what it held.
        $parts = [];
        array_walk_recursive($v, static function (mixed $x, mixed $k) use (&$parts): void {
            if (is_scalar($x) && $x !== '') {
                $parts[] = $k . ': ' . (is_bool($x) ? ($x ? 'on' : 'off') : (string) $x);
            }
        });

        return '"' . mb_strimwidth(implode(', ', $parts), 0, 120, '...') . '"';
    }

    /** @param array{keys:list<string>, path:string} $c */
    private static function keyOf(array $c): string
    {
        return $c['keys'] !== [] ? implode("\0", $c['keys']) : $c['path'];
    }

    /**
     * The value at a stored key path; "#id" picks a list item by id.
     *
     * @param list<string> $keys
     */
    private static function at(mixed $doc, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (!is_array($doc)) {
                return null;
            }
            if (str_starts_with($k, '#') && array_is_list($doc)) {
                $found = null;
                foreach ($doc as $item) {
                    if (is_array($item) && isset($item['id']) && (string) $item['id'] === substr($k, 1)) {
                        $found = $item;
                        break;
                    }
                }
                $doc = $found;
                continue;
            }
            $doc = $doc[$k] ?? null;
        }

        return $doc;
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
