<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Core\Str;
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

    /**
     * The UTF-8 byte order mark, for the DOWNLOAD only.
     *
     * Windows Excel opens a double-clicked .csv as cp1252 unless the file
     * starts with these three bytes, so every "3" en dash "5" days cell
     * arrived as mojibake and "Türkiye" as "TÃ¼rkiye" in the file the owner
     * keeps or sends to a carrier. It is NOT put on the clipboard text: a
     * BOM pasted into a mail or a chat is an invisible character at the
     * front of the first cell.
     */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * The rates with the comment row that says what they are, as one text.
     *
     * ONE BUILDER FOR THE DOWNLOAD AND THE COPY BUTTON. "Copy as CSV" used to
     * copy the unpublished DRAFT with no first line at all, beside a
     * "Download as CSV" that gave the live prices with a version line - two
     * buttons that looked the same, were labelled the same, and handed out
     * different prices (2026-10-25). Both now come through here, with the
     * same comment row, so what reaches the clipboard and what reaches the
     * file can only differ by the BOM.
     */
    public static function document(string $comment, Site $site): string
    {
        return self::comment($comment) . self::EOL . self::render($site);
    }

    /** "# Speedat published rates - version N - published ... - exported ... UTC" */
    public static function liveComment(int $version, string $publishedAt, \DateTimeInterface $now): string
    {
        return '# Speedat published rates - version ' . $version
            . ' - published ' . $publishedAt
            . ' - exported ' . $now->format('Y-m-d H:i') . ' UTC';
    }

    /** "# UNPUBLISHED DRAFT - ... - branched from published version N - exported ... UTC" */
    public static function draftComment(int $baseVersion, \DateTimeInterface $now): string
    {
        return '# UNPUBLISHED DRAFT - these prices are not on the website'
            . ' - branched from published version ' . $baseVersion
            . ' - exported ' . $now->format('Y-m-d H:i') . ' UTC';
    }

    /**
     * A transit time as Excel will not mistake for a date: digit-hyphen-digit
     * becomes digit, en dash, digit.
     *
     * "7-10" in a CSV cell opens in Excel as 10-Jul and is saved back as a
     * date serial. The owner types a hyphen because it is the key on the
     * keyboard, so `Http\Admin\DraftForm` stores what this returns, and
     * `render()` applies it again on the way out for a value stored before
     * the form did. Only that narrow shape is touched: "on request" is left
     * as typed. Both pricing engines read the numbers either side of any
     * separator (`Pricing\Dates::parseDaysRange()` and its pricing.js twin),
     * so no delivery estimate changes.
     */
    public static function days(string $typed): string
    {
        $squished = Str::squish($typed);

        return preg_replace('/(\d)\s*-\s*(?=\d)/', "\$1\u{2013}", $squished) ?? $squished;
    }

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
                $row[] = self::days($rate?->days ?? '');
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

    /**
     * The comment row, stripped of everything that would make it more than one
     * cell or more than one line.
     *
     * Nothing a person typed reaches it - the version numbers and the timestamps
     * are the database's - but it is built by concatenation, and a line that is
     * built by concatenation gets its separators taken away from it rather than
     * being trusted not to contain any.
     */
    private static function comment(string $text): string
    {
        return str_replace([',', '"', "\r", "\n"], ' ', $text);
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
