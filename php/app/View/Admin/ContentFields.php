<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * "Website pages" - the words on the site. Port of ContentForm.tsx, with the
 * same labels and the same "blank = automatic" hints.
 *
 * Three fields are deliberately blank-able: the site writes them from the
 * rate card itself (the pickup city, the live countries, the lowest 1 kg
 * price, the day span, the pickup charge), so they can never say a number the
 * rates do not. `stepsTitle`, `steps` and `servicesTitle` render nowhere since
 * v3 and are not offered here; the keys stay in the stored record.
 *
 * The automatic caption used to end "documents up to 0.5 kg". The owner
 * removed the flat document rate from the product on 2026-09-27, so the hint
 * that described it would have been telling the owner to expect a sentence the
 * site can no longer write.
 */
final class ContentFields
{
    /** A headline longer than this wraps to three lines on a laptop. */
    public const HEADLINE_MAX = 60;

    /**
     * @return list<array{heading:string,rows:list<array{type:string,fields:list<Field>}>}>
     */
    public static function groups(Site $site, bool $readOnly = false): array
    {
        $c = static fn (string $key): string => $site->content($key);

        return [
            self::seoGroup($site, $readOnly),
            ['heading' => 'Home', 'rows' => [
                ['type' => 'grid2', 'fields' => [
                    Field::text('content[heroTitle]', 'Headline (blank = automatic)', $c('heroTitle'), '', self::headlineHint($c('heroTitle')), 200, $readOnly),
                    Field::text(
                        'content[heroSub]',
                        'Line under the headline (blank = automatic)',
                        $c('heroSub'),
                        '',
                        "Blank = automatic: the lowest 1 kg price (pickup included) and the day span, e.g. 1 kg from PKR 5,000 \u{b7} 3 to 10 days. While prices are on hold: Pickup in Lahore \u{b7} 3 to 10 days. Keep your own line to about ten words so the country tiles stay on the first phone screen.",
                        200,
                        $readOnly,
                    ),
                    Field::text('content[promise]', 'Line under the calculator (blank = none)', $c('promise'), '', '', 200, $readOnly),
                ]],
                ['type' => 'field', 'fields' => [
                    Field::area('content[stats]', 'Extra line under the headline (optional, one per line: Label | Value; blank = none)', $c('stats'), 'Years in business | 12', '', false, 2000, $readOnly),
                ]],
            ]],
            ['heading' => 'Rates board', 'rows' => [
                ['type' => 'grid2', 'fields' => [
                    Field::text('content[routesTitle]', 'Caption', $c('routesTitle'), '', 'The heading of the rates table on the home page and on Services, e.g. Rates.', 120, $readOnly),
                    Field::text(
                        'content[routesNote]',
                        'Second line of the caption (blank = automatic)',
                        $c('routesNote'),
                        '',
                        "Blank = automatic: the parcel weight and the pickup charge, e.g. 1 kg parcel \u{b7} pickup PKR 500 included.",
                        200,
                        $readOnly,
                    ),
                ]],
            ]],
            ['heading' => 'Services page', 'rows' => [
                ['type' => 'field', 'fields' => [
                    Field::text('content[servicesLede]', 'Line under the heading', $c('servicesLede'), '', '', 300, $readOnly),
                ]],
                ['type' => 'field', 'fields' => [
                    Field::area('content[services]', 'Services, one per line: icon | Title | text (the icon word is kept for older pages and not shown)', $c('services'), '', '', false, 4000, $readOnly),
                ]],
            ]],
            ['heading' => 'Contact line (inner pages)', 'rows' => [
                ['type' => 'grid2', 'fields' => [
                    Field::text('content[ctaTitle]', 'Heading (optional)', $c('ctaTitle'), '', '', 160, $readOnly),
                    Field::text('content[ctaSub]', 'Line under the heading (optional)', $c('ctaSub'), '', '', 300, $readOnly),
                ]],
            ]],
            ['heading' => 'About page', 'rows' => [
                ['type' => 'field', 'fields' => [
                    Field::area('content[story]', 'Our story (one paragraph per line)', $c('story'), '', '', false, 8000, $readOnly),
                ]],
                ['type' => 'grid2', 'fields' => [
                    Field::area('content[mission]', 'Mission (optional)', $c('mission'), '', '', false, 2000, $readOnly),
                    Field::area('content[vision]', 'Vision (optional)', $c('vision'), '', '', false, 2000, $readOnly),
                ]],
                ['type' => 'field', 'fields' => [
                    Field::area('content[values]', 'Values (optional), one per line: Value | one-line explanation', $c('values'), '', '', false, 4000, $readOnly),
                ]],
            ]],
            ['heading' => 'Contact page', 'rows' => [
                ['type' => 'field', 'fields' => [
                    Field::text('content[contactLede]', 'Line under the heading', $c('contactLede'), '', '', 300, $readOnly),
                ]],
                ['type' => 'grid2', 'fields' => [
                    Field::text('content[address]', 'Office address', $c('address'), '', '', 300, $readOnly),
                    Field::text('content[hours]', 'Working hours', $c('hours'), '', '', 200, $readOnly),
                    Field::text('content[phone2]', 'Second phone number (optional)', $c('phone2'), '', '', 40, $readOnly),
                    Field::text('content[mapUrl]', 'Google Maps link (optional; blank = search by address)', $c('mapUrl'), '', '', 500, $readOnly),
                ]],
            ]],
            ['heading' => 'FAQ page', 'rows' => [
                ['type' => 'field', 'fields' => [
                    Field::text('content[faqLede]', 'Line under the heading (optional)', $c('faqLede'), '', '', 300, $readOnly),
                ]],
                ['type' => 'field', 'fields' => [
                    Field::area('content[faq]', 'Questions, one per line: Question? | Answer', $c('faq'), '', '', false, 8000, $readOnly),
                ]],
            ]],
        ];
    }

    /**
     * Words in the record that are facts about the business rather than copy:
     * where the office is, when it is open, how to ring it. They are the
     * owner's, and are not on the website-text screen.
     */
    private const OWNER_ONLY = ['address', 'hours', 'phone2', 'mapUrl'];

    /**
     * The groups as the website-text screen draws them: search titles first,
     * and without the owner's own fields.
     *
     * @return list<array{heading:string,rows:list<array{type:string,fields:list<Field>}>}>
     */
    public static function pageGroups(Site $site): array
    {
        $out = [];

        foreach (self::groups($site) as $group) {
            $rows = [];
            foreach ($group['rows'] as $row) {
                $fields = array_values(array_filter(
                    $row['fields'],
                    static fn (Field $f): bool => !in_array(self::key($f), self::OWNER_ONLY, true),
                ));
                if ($fields !== []) {
                    $rows[] = ['type' => $row['type'], 'fields' => $fields];
                }
            }
            if ($rows !== []) {
                $out[] = ['heading' => $group['heading'], 'rows' => $rows];
            }
        }

        return $out;
    }

    /**
     * Every field the website-text screen may write, by its name in the
     * document. THIS LIST IS THE PERMISSION - `Http\Admin\PagesForm` writes
     * nothing that is not on it - and it is made from what the screen draws, so
     * the two cannot differ.
     *
     * @return array<string,Field>
     */
    public static function pageFields(): array
    {
        $out = [];

        foreach (self::pageGroups(Site::fromArray([])) as $group) {
            foreach ($group['rows'] as $row) {
                foreach ($row['fields'] as $field) {
                    $out[self::key($field)] = $field;
                }
            }
        }

        return $out;
    }

    /** `content[heroTitle]` -> `heroTitle`. */
    private static function key(Field $field): string
    {
        return preg_match('/^content\[([A-Za-z0-9]+)\]$/', $field->name, $m) === 1 ? $m[1] : '';
    }

    /**
     * On-page SEO, page by page: the title and the sentence a search engine
     * shows, and the page's own heading. Blank is always "as it is now".
     *
     * @return array{heading:string,rows:list<array{type:string,fields:list<Field>}>}
     */
    private static function seoGroup(Site $site, bool $readOnly): array
    {
        $rows = [];

        foreach (['Home' => 'Home', 'Services' => 'Services', 'About' => 'About', 'Contact' => 'Contact', 'Faq' => 'FAQ'] as $key => $page) {
            $title = 'seo' . $key . 'Title';
            $desc  = 'seo' . $key . 'Desc';
            $h1    = strtolower($key) . 'H1';

            $fields = [
                Field::text('content[' . $title . ']', $page . ': Google title (blank = automatic)', $site->content($title), '', self::lengthHint($site->content($title), 60), 120, $readOnly),
                Field::text('content[' . $desc . ']', $page . ': Google description (blank = automatic)', $site->content($desc), '', self::lengthHint($site->content($desc), 160), 300, $readOnly),
            ];
            if ($key !== 'Home') {
                $fields[] = Field::text('content[' . $h1 . ']', $page . ': heading on the page (blank = automatic)', $site->content($h1), '', '', 120, $readOnly);
            }

            $rows[] = ['type' => 'grid2', 'fields' => $fields];
        }

        return ['heading' => 'Search engines (SEO)', 'rows' => $rows];
    }

    /** "54 of 60 characters", or what happens past the number. */
    private static function lengthHint(string $text, int $best): string
    {
        $len = mb_strlen(trim($text));

        return match (true) {
            $len === 0    => 'Best under ' . $best . ' characters.',
            $len <= $best => $len . ' of ' . $best . ' characters.',
            default       => $len . ' characters: Google usually cuts it at about ' . $best . '.',
        };
    }

    /**
     * The hint under the headline box: what blank means, or how far over the
     * limit the owner's own headline is. Same three sentences as the React
     * form, chosen by the same rule.
     */
    public static function headlineHint(string $headline): string
    {
        $len = mb_strlen(trim($headline));

        if ($len === 0) {
            return 'Blank = automatic: the first pickup city and the countries on the site, e.g. Lahore to Australia, Canada, France and Germany.';
        }

        if ($len > self::HEADLINE_MAX) {
            return 'Too long: ' . $len . ' characters. Over ' . self::HEADLINE_MAX
                . " the headline wraps to three lines on a laptop - shorten it, or leave it blank for the automatic one.";
        }

        return $len . ' of ' . self::HEADLINE_MAX . ' characters. Printed exactly as typed. Leave it blank for the automatic one: the first pickup city and the countries on the site.';
    }
}
