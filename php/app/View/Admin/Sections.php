<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The sections of the rates editor, in page order. The page's anchors, the
 * sidebar's sub-list and the sticky section tabs all read this, so the
 * `sec-*` ids they link to can never drift apart. Port of
 * `src/components/admin/sections.ts`.
 *
 * NO "IMPORT FROM EXCEL". The Next.js editor's second section was "Import
 * from Excel"; the owner removed that feature from the product on
 * 2026-09-27, so it is gone here - not hidden, not disabled, not replaced by
 * a "coming soon" card. The numbered eyebrows are computed from this list
 * rather than written into each template, so nothing is left counting from
 * the old number.
 *
 * REVIEWS joined the list on 2026-10-05 (owner): the admin picks which Google
 * Business reviews the home page shows, so the editor needs a section for them.
 * It sits after "Popup" and before "History", which pushed History down one.
 */
final class Sections
{
    /** @var list<array{0:string,1:string}> id and the label the nav prints */
    public const ALL = [
        ['rates', 'Rates'],
        ['bulk', 'Bulk adjust'],
        ['test', 'Test a price'],
        ['settings', 'Settings'],
        ['content', 'Website pages'],
        ['promo', 'Popup'],
        ['reviews', 'Reviews'],
        ['history', 'History'],
    ];

    /**
     * The sections as the templates want them: id, label, the `sec-*` anchor
     * and the two-digit number of the eyebrow above the heading.
     *
     * @return list<array{id:string,label:string,anchor:string,no:string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ALL as $i => [$id, $label]) {
            $out[] = [
                'id'     => $id,
                'label'  => $label,
                'anchor' => 'sec-' . $id,
                'no'     => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            ];
        }

        return $out;
    }

    /** "01", "02", ... the numbered eyebrow above a section's heading. */
    public static function no(string $id): string
    {
        foreach (self::ALL as $i => [$sectionId]) {
            if ($sectionId === $id) {
                return str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            }
        }

        // An unknown id is a typo in a template, not a runtime condition; an
        // empty eyebrow is visible in review and breaks nothing for the owner.
        return '';
    }

    /** `#sec-rates` - the in-page link the nav and the tabs use. */
    public static function anchor(string $id): string
    {
        return '#sec-' . $id;
    }
}
