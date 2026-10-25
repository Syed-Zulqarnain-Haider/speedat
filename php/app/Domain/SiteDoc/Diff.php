<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Num;
use Speedat\Domain\Pricing\Rate;

/**
 * What changed between two site documents. Port of `diffSite`, `fieldChange`
 * and `summarise` in src/lib/site/diff.ts. Pure: the admin shows it, the
 * publish action records it, the tests pin it.
 *
 * THE WORDING IS THE INTERFACE. These strings are what the owner reads in the
 * "review before publishing" panel and what the audit log keeps forever, so
 * they are copied from the TypeScript character for character - middle dots,
 * en dashes, ellipses and all. A "nicer" phrasing here is a behaviour change.
 *
 * The blocking and advisory checks (`validateSite`, `warnSite`) live next door
 * in `Validate`; they were ported first, for the golden vectors.
 */
final class Diff
{
    /** Rate moves at or above this percentage are flagged in the review. */
    public const FLAG_PCT = 25;

    /** Most a review line shows per side; the audit log keeps these lines, so they stay short. */
    private const SHOW = 90;

    /** Characters kept before a change that does not fit whole, and after one that does. */
    private const CTX  = 30;
    private const TAIL = 20;

    /** The groups compared field by field, in the order the review lists them. @var list<string> */
    private const GROUPS = ['company', 'settings', 'content', 'promo'];

    public static function of(SiteData $a, SiteData $b): DiffResult
    {
        $count  = 0;
        $byDest = [];
        $lines  = [];

        $currency = $b->card->settings->currency;

        $am = [];
        foreach ($a->card->destinations as $dest) {
            $am[$dest->id] = $dest;
        }
        $bm = [];
        foreach ($b->card->destinations as $dest) {
            $bm[$dest->id] = $dest;
        }

        foreach ($bm as $id => $x) {
            $y = $am[$id] ?? null;

            if ($y === null) {
                $count++;
                $byDest[$id] = true;
                $lines[]     = new DiffLine(
                    DiffLine::ADDED,
                    'Added',
                    null,
                    $x->name . ($x->active ? '' : ' (hidden from site)')
                );
                continue;
            }

            if ($x->name !== $y->name) {
                $count++;
                $byDest[$id] = true;
                $lines[]     = new DiffLine(DiffLine::RENAMED, 'Renamed', $y->name, $x->name);
            }

            if ($x->active !== $y->active) {
                $count++;
                $byDest[$id] = true;
                $lines[]     = new DiffLine(
                    DiffLine::VISIBILITY,
                    $x->name . ': ' . ($x->active ? 'now shown on the site' : 'hidden from the site')
                );
            }

            foreach ($b->card->services as $service) {
                $r1 = $y->rate($service->id);
                $r2 = $x->rate($service->id);

                foreach (['first', 'addl', 'days'] as $field) {
                    $v1 = self::rateField($r1, $field);
                    $v2 = self::rateField($r2, $field);

                    if (Text::js($v1) === Text::js($v2)) {
                        continue;
                    }

                    $count++;
                    $byDest[$id] = true;

                    $label = match ($field) {
                        'days'  => 'days',
                        'first' => 'first slab',
                        default => 'per step',
                    };

                    $pct = $field === 'days' ? null : self::movePct($v1, $v2);

                    $lines[] = new DiffLine(
                        DiffLine::RATE,
                        $x->name . " \u{00b7} " . $service->name . ' ' . $label,
                        $field === 'days' ? Text::js($v1) : self::money($v1, $currency),
                        $field === 'days' ? Text::js($v2) : self::money($v2, $currency),
                        self::flag($pct),
                    );
                }

                // Grid prices, one line per kilogram that changed.
                $g1 = $r1?->boxes() ?? [];
                $g2 = $r2?->boxes() ?? [];

                foreach (self::gridKeys($g1, $g2) as $kg) {
                    $v1 = $g1[$kg] ?? null;
                    $v2 = $g2[$kg] ?? null;

                    if (self::sameBox($v1, $v2)) {
                        continue;
                    }

                    $count++;
                    $byDest[$id] = true;

                    $lines[] = new DiffLine(
                        DiffLine::RATE,
                        $x->name . " \u{00b7} " . $service->name . ' ' . $kg . ' kg',
                        self::money($v1, $currency),
                        self::money($v2, $currency),
                        self::flag(self::movePct($v1, $v2)),
                    );
                }
            }
        }

        foreach ($am as $id => $y) {
            if (isset($bm[$id])) {
                continue;
            }

            $count++;
            $byDest[$id] = true;
            $lines[]     = new DiffLine(DiffLine::REMOVED, 'Removed', $y->name);
        }

        foreach (self::GROUPS as $group) {
            $ga = self::groupOf($a, $group);
            $gb = self::groupOf($b, $group);

            foreach ($gb as $key => $value) {
                if (Text::js($ga[$key] ?? null) === Text::js($value)) {
                    continue;
                }

                $count++;
                $change = self::fieldChange($ga[$key] ?? null, $value);
                $base   = match ($group) {
                    'content' => "Website text \u{00b7} " . $key,
                    'promo'   => "Popup \u{00b7} " . $key,
                    default   => (string) $key,
                };

                $lines[] = new DiffLine(
                    DiffLine::FIELD,
                    $change->where !== null ? $base . ' (' . $change->where . ')' : $base,
                    $change->old,
                    $change->new,
                );
            }
        }

        if ($a->live !== $b->live) {
            $count++;
            $lines[] = new DiffLine(
                DiffLine::FIELD,
                'Rates are live',
                $a->live ? 'true' : 'false',
                $b->live ? 'true' : 'false',
            );
        }

        // Reviews are a LIST, not a field map, so the group loop above cannot
        // see them: they are compared whole here. Any change to the testimonials
        // (added, removed, edited, or a Show-on-site toggle) counts as one
        // change and is listed. Without this a reviews-only edit counted as
        // zero changes and could not be published, and - worse - a draft that
        // was branched before reviews existed would wipe them on publish with
        // nothing shown in the Review card. (Popup is handled above, now that
        // it is in GROUPS.)
        // json_encode, not Text::js: the reviews are a nested list and Text::js
        // is built for scalar field values, so it collapses two different lists
        // to the same string and the change would go unseen. A full encode is
        // the reliable deep comparison here.
        if (json_encode($a->reviews->toArray()) !== json_encode($b->reviews->toArray())) {
            $count++;
            $na = $a->reviews->count();
            $nb = $b->reviews->count();
            $lines[] = new DiffLine(
                DiffLine::FIELD,
                'Reviews',
                $na . ' review' . ($na === 1 ? '' : 's') . ' shown or saved',
                $nb . ' review' . ($nb === 1 ? '' : 's') . ' shown or saved',
            );
        }

        $ap = [];
        foreach ($a->importProfiles as $profile) {
            $ap[] = $profile->signature;
        }
        $bp = [];
        foreach ($b->importProfiles as $profile) {
            $bp[] = $profile->signature;
        }

        if (implode("\n", $ap) !== implode("\n", $bp)) {
            $count++;
            $last    = $b->importProfiles === [] ? null : $b->importProfiles[count($b->importProfiles) - 1];
            $lines[] = new DiffLine(
                DiffLine::PROFILE,
                'Import layout remembered',
                null,
                ($last !== null && $last->name !== '') ? $last->name : 'sheet',
            );
        }

        return new DiffResult($count, $byDest, $lines);
    }

    /** One line for the version history, e.g. "12 changes: 3 destinations, settings or text". */
    public static function summarise(DiffResult $d): string
    {
        $dests  = count($d->byDest);
        $fields = 0;
        $layout = false;

        foreach ($d->lines as $line) {
            if ($line->kind === DiffLine::FIELD) {
                $fields++;
            }
            if ($line->kind === DiffLine::PROFILE) {
                $layout = true;
            }
        }

        $bits = [];
        if ($dests > 0) {
            $bits[] = $dests . ' destination' . ($dests === 1 ? '' : 's');
        }
        if ($fields > 0) {
            $bits[] = 'settings or text';
        }
        if ($layout) {
            $bits[] = 'import layout';
        }

        return $d->count . ' change' . ($d->count === 1 ? '' : 's')
            . ($bits === [] ? '' : ': ' . implode(', ', $bits));
    }

    /**
     * Old and new text for a changed field, with `where` naming the changed
     * line(s) of a multi-line field (FAQ, services, story, add-ons, holidays)
     * so only those lines are shown; single lines are windowed around the
     * change.
     */
    public static function fieldChange(mixed $a, mixed $b): FieldChange
    {
        $sa = Text::js($a);
        $sb = Text::js($b);

        $la = $sa === '' ? [] : preg_split('/\r?\n/', $sa);
        $lb = $sb === '' ? [] : preg_split('/\r?\n/', $sb);
        $la = $la === false ? [] : $la;
        $lb = $lb === false ? [] : $lb;

        if (count($la) <= 1 && count($lb) <= 1) {
            [$old, $new] = self::around($sa, $sb);

            return new FieldChange($old, $new);
        }

        $countA = count($la);
        $countB = count($lb);

        $i = 0;
        while ($i < $countA && $i < $countB && $la[$i] === $lb[$i]) {
            $i++;
        }

        $j = 0;
        while ($j < $countA - $i && $j < $countB - $i && $la[$countA - 1 - $j] === $lb[$countB - 1 - $j]) {
            $j++;
        }

        $oa = array_slice($la, $i, $countA - $j - $i);
        $ob = array_slice($lb, $i, $countB - $j - $i);

        $n    = max(count($oa), count($ob));
        $span = $n <= 1 ? 'line ' . ($i + 1) : 'lines ' . ($i + 1) . "\u{2013}" . ($i + $n);

        $where = $oa === [] ? $span . ' added' : ($ob === [] ? $span . ' removed' : $span);

        [$old, $new] = self::around(implode(" \u{23ce} ", $oa), implode(" \u{23ce} ", $ob));

        return new FieldChange($old, $new, $where);
    }

    /* ---------------------------------------------------------- internals */

    /**
     * Cut two differing texts to a window that holds the difference.
     *
     * Cutting both to their first 90 characters hid every edit made past them:
     * old and new read the same and the owner could not see what he was about
     * to publish. The window starts at the beginning when that reaches the
     * change, else as late as the change needs; a change longer than the window
     * keeps its run-up instead.
     *
     * Lengths are counted in characters, which is what JavaScript counts here:
     * every character the copy uses is in the Basic Multilingual Plane, so a
     * UTF-16 code unit and a UTF-8 character are the same thing.
     *
     * @return array{0:string,1:string}
     */
    private static function around(string $x, string $y): array
    {
        $cx = Text::chars($x);
        $cy = Text::chars($y);
        $lx = count($cx);
        $ly = count($cy);

        if ($lx <= self::SHOW && $ly <= self::SHOW) {
            return [$x, $y];
        }

        $min = min($lx, $ly);

        $p = 0;
        while ($p < $min && $cx[$p] === $cy[$p]) {
            $p++;
        }

        $q = 0;
        while ($q < $min - $p && $cx[$lx - 1 - $q] === $cy[$ly - 1 - $q]) {
            $q++;
        }

        $need  = max(min($lx, $lx - $q + self::TAIL), min($ly, $ly - $q + self::TAIL));
        $start = max(0, $need - self::SHOW);
        if ($start > $p - self::CTX) {
            $start = max(0, $p - self::CTX);
        }

        return [self::cut($cx, $start), self::cut($cy, $start)];
    }

    /** @param list<string> $chars */
    private static function cut(array $chars, int $start): string
    {
        $len = count($chars);
        $end = min($len, $start + self::SHOW);

        return ($start > 0 ? "\u{2026}" : '')
            . implode('', array_slice($chars, $start, $end - $start))
            . ($end < $len ? "\u{2026}" : '');
    }

    /** The value a rate field has, or null when the rate (or the field) is absent. */
    private static function rateField(?Rate $rate, string $field): float|string|null
    {
        if ($rate === null) {
            // `y.rates[sv.id] ?? {}`: every field of a missing rate reads as absent.
            return $field === 'days' ? '' : null;
        }

        return match ($field) {
            'first' => $rate->first,
            'addl'  => $rate->addl,
            default => $rate->days,
        };
    }

    /** "-" for a blank, otherwise the amount with its currency. */
    private static function money(mixed $v, string $currency): string
    {
        if ($v === null || $v === '') {
            return "-";
        }

        return Money::fmtMoney(Num::jsNumber($v), $currency);
    }

    /** The percentage a price moved, or null when one side is not a price or the old one was zero. */
    private static function movePct(mixed $v1, mixed $v2): ?int
    {
        if (!is_float($v1) || !is_float($v2) || !Num::isNum($v1) || !Num::isNum($v2) || !($v1 > 0)) {
            return null;
        }

        // Math.round, not PHP's round(): they disagree on exact halves below
        // zero, and a -12.5% move would print as -13% on one side and -12% on
        // the other.
        return (int) Money::jsRound((($v2 - $v1) / $v1) * 100);
    }

    private static function flag(?int $pct): ?string
    {
        if ($pct === null || abs($pct) < self::FLAG_PCT) {
            return null;
        }

        return ($pct > 0 ? '+' : '') . $pct . "% - please double-check";
    }

    /**
     * The union of two grids' keys, in insertion order, sorted by weight - the
     * order `[...new Set([...])].sort((p, q) => Number(p) - Number(q))` gives.
     *
     * @param  array<string,float|null> $g1
     * @param  array<string,float|null> $g2
     * @return list<string>
     */
    private static function gridKeys(array $g1, array $g2): array
    {
        $keys = [];
        foreach ([$g1, $g2] as $grid) {
            foreach ($grid as $key => $_) {
                $keys[(string) $key] = true;
            }
        }

        $out = array_keys($keys);
        // PHP's sort has been stable since 8.0, like JavaScript's, so two keys
        // that read as the same number keep the order they were seen in.
        usort($out, static fn (string $p, string $q): int => Num::jsNumber($p) <=> Num::jsNumber($q));

        return $out;
    }

    /** `(v1 ?? "") === (v2 ?? "")` for two grid boxes. */
    private static function sameBox(?float $v1, ?float $v2): bool
    {
        if ($v1 === null || $v2 === null) {
            return $v1 === null && $v2 === null;
        }

        return $v1 === $v2;
    }

    /**
     * One comparable group of a document, in the order the TypeScript object
     * carries its keys.
     *
     * @return array<string,mixed>
     */
    private static function groupOf(SiteData $site, string $group): array
    {
        return match ($group) {
            'company'  => $site->company->toArray(),
            'settings' => $site->card->settings->toArray(),
            'promo'    => $site->promo->toArray(),
            default    => $site->content->toArray(),
        };
    }
}
