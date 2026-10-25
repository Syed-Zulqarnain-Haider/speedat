<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Num;
use Speedat\Domain\Pricing\Rate;
use Speedat\Domain\Pricing\Service;

/**
 * One row of the rates table, with every field name and value resolved.
 * Port of the row RatesTable.tsx renders, minus its client-side state.
 *
 * The React table kept the whole draft in the browser and diffed it there.
 * This one cannot: the draft lives in MySQL and the server decides what
 * changed, so the row arrives knowing whether it is changed and carrying the
 * LIVE values as a JSON snapshot (`data-live`) for the Undo button. That is
 * the only copy of the live document the browser ever sees, it is one row
 * wide, and without JavaScript the same button is an ordinary POST that does
 * the same thing on the server.
 *
 * Field names are `dest[<id>][...]`, so the server reads a row by its id
 * wherever the post came from.
 *
 * ONE FORM PER COUNTRY, NOT ONE FORM FOR THE TABLE (`formId`). PHP stops
 * reading a request body at `max_input_vars` - 1000 on this host - without
 * telling the script, and a table that posted every country at once walked
 * through that line the day a third service was added: 1009 variables, and
 * the editor could not save at all. A row now posts ITSELF - its name,
 * whether it is shown, and its own ladders - which is about ninety variables
 * however many countries the document grows to. The inputs stay where they
 * are in the table and are tied to the row's form with the `form` attribute,
 * because a <form> cannot be a child of <tbody> and a row is still a row.
 */
final class DestinationRow
{
    /** @param list<RateCells> $cells */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $active,
        public readonly bool $changed,
        /** True when this row's per-kilogram boxes are rendered open. */
        public readonly bool $open,
        /** Hidden from the table by the search filter; the row stays in the form so nothing is lost on save. */
        public readonly bool $hidden,
        public readonly int $position,
        public readonly array $cells,
        public readonly string $nameField,
        public readonly string $activeField,
        public readonly string $positionField,
        /** The `id` of the per-kilogram fold row, and what [data-fold-toggle] points at. */
        public readonly string $foldId,
        /**
         * The `id` of THIS COUNTRY'S form - every input in the row carries
         * `form="<formId>"`, so saving the row posts the row and nothing else.
         */
        public readonly string $formId,
        /** JSON: input name => live value, for Undo. "" when the row is not in the live version. */
        public readonly string $liveJson,
        /** The key the filter matches on (accent-free, lower case), printed as data-name. */
        public readonly string $searchKey,
    ) {
    }

    /**
     * Build a row from the draft destination, the live one (null when the row
     * is new) and the services and kilogram steps of the draft.
     *
     * @param list<Service> $services
     * @param list<int>     $kgs      the whole-kilogram boxes, empty in slab mode
     */
    public static function build(
        Destination $draft,
        ?Destination $live,
        array $services,
        array $kgs,
        int $position,
        bool $changed,
        bool $open,
        bool $hidden = false,
    ): self {
        $prefix = 'dest[' . $draft->id . ']';
        $cells  = [];
        $liveMap = [];

        $nameField   = $prefix . '[name]';
        $activeField = $prefix . '[active]';

        if ($live !== null) {
            $liveMap[$nameField]   = $live->name;
            $liveMap[$activeField] = $live->active;
        }

        foreach ($services as $service) {
            $rate     = $draft->rate($service->id);
            $liveRate = $live?->rate($service->id);
            $base     = $prefix . '[rates][' . $service->id . ']';

            $boxes = self::ladder($draft->name, $service->name, $base, $rate, $liveRate, $live !== null, $kgs);

            foreach ($boxes as $box) {
                if ($live !== null) {
                    $liveMap[$box['name']] = self::box($liveRate, (string) $box['kg']);
                }
            }

            // No `doc` field: the document rate left the product on 2026-09-27
            // (owner). Papers are priced by weight like anything else, so there
            // is no flat box to post and nothing for Undo to restore.
            $fields = [
                'first' => $base . '[first]',
                'addl'  => $base . '[addl]',
                'days'  => $base . '[days]',
            ];

            if ($live !== null) {
                $liveMap[$fields['first']] = self::num($liveRate?->first);
                $liveMap[$fields['addl']]  = self::num($liveRate?->addl);
                $liveMap[$fields['days']]  = $liveRate?->days ?? '';
            }

            $cells[] = new RateCells(
                serviceId: $service->id,
                serviceName: $service->name,
                firstField: $fields['first'],
                firstValue: self::num($rate?->first),
                addlField: $fields['addl'],
                addlValue: self::num($rate?->addl),
                daysField: $fields['days'],
                daysValue: $rate?->days ?? '',
                boxes: $boxes,
                gridSummary: self::summary($rate, $kgs),
                fillStartField: $base . '[fill][start]',
                fillStepField: $base . '[fill][step]',
                ladderId: 'ladder-' . $draft->id . '-' . $service->id,
                ladderNote: self::ladderNote($boxes),
                ladderFault: self::hasFault($boxes),
                ladderFilled: self::countFilled($boxes),
                ladderTotal: count($boxes),
            );
        }

        $json = '';
        if ($liveMap !== []) {
            $encoded = json_encode(
                $liveMap,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $json = is_string($encoded) ? $encoded : '';
        }

        return new self(
            id: $draft->id,
            name: $draft->name,
            active: $draft->active,
            changed: $changed,
            open: $open,
            hidden: $hidden,
            position: $position,
            cells: $cells,
            nameField: $nameField,
            activeField: $activeField,
            positionField: $prefix . '[position]',
            foldId: 'fold-' . $draft->id,
            formId: 'row-' . $draft->id,
            liveJson: $json,
            searchKey: Format::nameKey($draft->name),
        );
    }

    /** How many columns the per-kilogram fold row spans, so it lines up under the table. */
    public function foldColumns(int $serviceCount): int
    {
        // name + on-site + (days) per service + the Prices column + actions
        return 2 + $serviceCount + 2;
    }

    /**
     * One service's ladder: a box per whole kilogram, each carrying what is
     * wrong with it and whether it differs from the live version.
     *
     * THE CHECKS ARE RUN HERE, ON THE SERVER, AND NOT ONLY IN admin.js. Three
     * things go wrong when a carrier's sheet is keyed by hand, and all three are
     * silent until a customer is quoted:
     *
     *   - `low`:  a kilogram priced BELOW the one under it. The ladder runs
     *             backwards, so a heavier parcel is cheaper than a lighter one.
     *   - `gap`:  a blank kilogram with priced kilograms on both sides. The
     *             engine charges the next heavier priced box there. This is a
     *             LEGITIMATE way to fill a ladder in - "3 and 4 kg go at the
     *             5 kg price" - so it is shown quietly and never in the colour
     *             of a fault. Marking twenty blanks in alarm orange on an
     *             ordinary sparse ladder is how a warning gets ignored.
     *   - `edited`: the box differs from what the website is serving right now.
     *
     * Doing it here means the marks are on the page with JavaScript switched
     * off, and admin.js recomputes exactly the same three rules as the owner
     * types. A rule that only exists in the browser is a rule the owner loses
     * the moment the script fails to load.
     *
     * @param  list<int> $kgs
     * @return list<array{kg:int,name:string,value:string,label:string,low:bool,gap:bool,edited:bool}>
     */
    private static function ladder(
        string $destName,
        string $serviceName,
        string $base,
        ?Rate $rate,
        ?Rate $liveRate,
        bool $haveLive,
        array $kgs,
    ): array {
        $boxes = [];

        // The last priced kilogram below this one, for the backwards check, and
        // whether anything at all is priced below it, for the gap check.
        $previous = null;
        $anyBelow = false;

        foreach ($kgs as $kg) {
            $value = self::box($rate, (string) $kg);
            $liveValue = $haveLive ? self::box($liveRate, (string) $kg) : $value;

            $low = false;
            if ($value !== '' && $previous !== null && (float) $value < $previous) {
                $low = true;
            }

            $boxes[] = [
                'kg'     => $kg,
                'name'   => $base . '[grid][' . $kg . ']',
                'value'  => $value,
                'label'  => $destName . ' ' . $serviceName . ' ' . $kg . ' kg price',
                'low'    => $low,
                // Filled in on the second pass, once we know what is above it.
                'gap'    => $value === '' && $anyBelow,
                'edited' => $haveLive && $value !== $liveValue,
            ];

            if ($value !== '') {
                $previous = (float) $value;
                $anyBelow = true;
            }
        }

        // A blank kilogram is only a GAP when something heavier is priced too;
        // the blanks after the last price are simply "we do not carry that
        // weight", which is a normal way to fill a ladder in.
        $seenAbove = false;
        for ($i = count($boxes) - 1; $i >= 0; $i--) {
            if ($boxes[$i]['value'] !== '') {
                $seenAbove = true;
                continue;
            }
            $boxes[$i]['gap'] = $boxes[$i]['gap'] && $seenAbove;
        }

        return $boxes;
    }

    /**
     * "3 kg is priced below 2 kg. 4 and 6 kg are blank between priced weights."
     * - the one sentence under the ladder, which the bad boxes point at with
     * aria-describedby. Empty when the ladder is sound.
     *
     * @param list<array{kg:int,name:string,value:string,label:string,low:bool,gap:bool,edited:bool}> $boxes
     */
    private static function ladderNote(array $boxes): string
    {
        $low = [];
        $gap = [];

        foreach ($boxes as $box) {
            if ($box['low']) {
                $low[] = $box['kg'];
            }
            if ($box['gap']) {
                $gap[] = $box['kg'];
            }
        }

        $parts = [];

        // The fault first, and in the language of money: a heavier parcel that
        // costs less is a hole a customer walks through.
        if ($low !== []) {
            $parts[] = self::kgList($low) . (count($low) === 1 ? ' is' : ' are')
                . ' priced below the kilogram underneath, so a heavier parcel costs less.';
        }

        // The blanks are stated, not complained about: leaving them is a normal
        // way to price, and the sentence says what the customer will be charged.
        if ($gap !== []) {
            $parts[] = self::kgList($gap) . (count($gap) === 1 ? ' is' : ' are')
                . ' blank, and charged at the next heavier price.';
        }

        return implode(' ', $parts);
    }

    /**
     * Is anything here actually WRONG, as opposed to merely blank? Only this
     * turns the note orange; a sparse ladder is stated in the quiet tone.
     *
     * @param list<array{kg:int,name:string,value:string,label:string,low:bool,gap:bool,edited:bool}> $boxes
     */
    private static function hasFault(array $boxes): bool
    {
        foreach ($boxes as $box) {
            if ($box['low']) {
                return true;
            }
        }

        return false;
    }

    /** "4 kg", "4 and 6 kg", "4, 6 and 9 kg" - the weights a note names. */
    /** @param list<int> $kgs */
    private static function kgList(array $kgs): string
    {
        // Long lists are cut off: naming twenty weights in a sentence is a
        // sentence nobody reads, and the boxes themselves are already marked.
        $shown = array_slice($kgs, 0, 6);
        $rest  = count($kgs) - count($shown);

        $text = count($shown) === 1
            ? $shown[0] . ' kg'
            : implode(', ', array_slice($shown, 0, -1)) . ' and ' . $shown[count($shown) - 1] . ' kg';

        return $rest > 0 ? $text . ' (and ' . $rest . ' more)' : $text;
    }

    /** @param list<array{kg:int,name:string,value:string,label:string,low:bool,gap:bool,edited:bool}> $boxes */
    private static function countFilled(array $boxes): int
    {
        $n = 0;
        foreach ($boxes as $box) {
            if ($box['value'] !== '') {
                $n++;
            }
        }

        return $n;
    }

    /** A price for an input box: JavaScript's String(n), or "" for "not offered". */
    private static function num(?float $v): string
    {
        return Num::isNum($v) ? Money::numberToString((float) $v) : '';
    }

    private static function box(?Rate $rate, string $kg): string
    {
        $boxes = $rate?->boxes() ?? [];

        return self::num($boxes[$kg] ?? null);
    }

    /**
     * "4,500 - 21,000", with "(12/25)" appended while boxes are still blank.
     * An em dash when the country has no per-kilogram price at all - the same
     * summary RatesTable prints beside the Prices button.
     *
     * @param list<int> $kgs
     */
    private static function summary(?Rate $rate, array $kgs): string
    {
        if ($kgs === []) {
            return "-";
        }

        $boxes  = $rate?->boxes() ?? [];
        $filled = [];
        foreach ($kgs as $kg) {
            if (Num::isNum($boxes[(string) $kg] ?? null)) {
                $filled[] = (float) $boxes[(string) $kg];
            }
        }

        if ($filled === []) {
            return "-";
        }

        $text = Format::fmtNum($filled[0]) . " \u{2013} " . Format::fmtNum($filled[count($filled) - 1]);

        return count($filled) < count($kgs)
            ? $text . ' (' . count($filled) . '/' . count($kgs) . ')'
            : $text;
    }
}
