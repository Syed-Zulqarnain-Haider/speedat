<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Num;

/**
 * The rates as a CSV the owner downloads or copies. Port of `toCsv` in
 * RatesTable.tsx, moved to the server because that is where the draft lives.
 *
 * THIS IS AN EXPORT AND ONLY AN EXPORT. The owner reads the file in Excel,
 * sends it to a carrier, keeps it as a record. Nothing reads a spreadsheet
 * back in - that feature was removed from the product on 2026-09-27 - so
 * there is no parser, no column map and no counterpart to this class.
 *
 * A cell starting with = + - @ is prefixed with an apostrophe: Excel executes
 * such a cell as a formula, and a destination a carrier named "=cmd" would
 * otherwise be a command in the owner's own spreadsheet.
 */
final class Csv
{
    /** CRLF, because the file is opened in Excel on Windows far more often than anywhere else. */
    private const EOL = "\r\n";

    /** The whole draft as CSV text. */
    public static function render(Site $site): string
    {
        $settings = $site->settings();
        $grid     = $site->isGrid();
        $kgs      = $grid ? $site->gridWeights() : [];

        // No "documents" column: the flat document rate left the product on
        // 2026-09-27 (owner). A carrier's sheet has weights and nothing else.
        $head = ['Destination', 'On site'];
        foreach ($site->services() as $service) {
            $head[] = $service->name . ' days';
            if ($grid) {
                foreach ($kgs as $kg) {
                    $head[] = $service->name . ' ' . $kg . ' kg';
                }
            } else {
                $head[] = $service->name . ' first ' . Money::numberToString($settings->firstKg) . ' kg';
                $head[] = $service->name . ' each +' . Money::numberToString($settings->stepKg) . ' kg';
            }
        }

        $rows = [self::line($head)];

        foreach ($site->destinations() as $dest) {
            $row = [$dest->name, $dest->active ? 'yes' : 'no'];
            foreach ($site->services() as $service) {
                $rate  = $dest->rate($service->id);
                $row[] = $rate?->days ?? '';
                if ($grid) {
                    $boxes = $rate?->boxes() ?? [];
                    foreach ($kgs as $kg) {
                        $row[] = self::num($boxes[(string) $kg] ?? null);
                    }
                } else {
                    $row[] = self::num($rate?->first);
                    $row[] = self::num($rate?->addl);
                }
            }
            $rows[] = self::line($row);
        }

        return implode(self::EOL, $rows) . self::EOL;
    }

    /** `speedat-international-courier-rates.csv` - what the download is called. */
    public static function filename(Site $site): string
    {
        $slug = Format::slug($site->company('name'));

        return ($slug === '' ? 'rates' : $slug . '-rates') . '.csv';
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    private static function cell(string $value): string
    {
        // Neutralise a formula before quoting, so the apostrophe is inside the quotes.
        $safe = preg_match("/^[=+\\-@\t\r]/", $value) === 1 ? "'" . $value : $value;

        return preg_match('/[",\n]/', $safe) === 1
            ? '"' . str_replace('"', '""', $safe) . '"'
            : $safe;
    }

    private static function num(?float $v): string
    {
        return Num::isNum($v) ? Money::numberToString((float) $v) : '';
    }
}
