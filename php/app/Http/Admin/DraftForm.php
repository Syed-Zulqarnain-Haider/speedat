<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\Request;
use Speedat\Core\Str;
use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\View\Admin\ContentFields;
use Speedat\View\Admin\Csv;
use Speedat\View\Admin\Field;
use Speedat\View\Admin\SettingsFields;
use Speedat\View\Admin\Site;

/**
 * The posted rates editor, turned back into a site document.
 *
 * This is the only place in the application that reads the editor's form, and
 * the rules are all here rather than spread across the eight actions that submit
 * it:
 *
 * IT IS A PATCH, NOT A DOCUMENT. It starts from the draft that is in the
 * database and changes what the form posted. Two things follow. A field the form
 * does not carry (the services list, the remembered layouts, the "rates are
 * live" flag) cannot be changed by posting one, and a destination id that is not
 * already in the draft is IGNORED - the only way a country enters the document
 * is the Add country action, so a hand-made form cannot invent one.
 *
 * THAT IS WHAT LETS THE EDITOR POST ONE COUNTRY AT A TIME. Since 2026-09-28 the
 * rates table is not one form: each row is its own, and a save carries that
 * country and the keys and nothing else - about ninety variables rather than
 * the 1009 the whole document reached when a third service was added, against
 * a `max_input_vars` of 1000 that cannot be raised from PHP and cannot be
 * trusted higher on shared hosting. Nothing here had to change for it. A post
 * that names one country patches that country; the rows it does not name keep
 * what is stored, in the order it is stored in, exactly as a row the search box
 * was hiding always did.
 *
 * WHICH FIELDS EXIST IS NOT WRITTEN HERE. `View\Admin\SettingsFields` and
 * `View\Admin\ContentFields` are what draw the Settings and Website pages
 * sections, and this reads THEIR list - name, type, length limit, and a select's
 * own options - to decide what it will accept. So the form and the parser cannot
 * drift: a field added to the form is accepted with its own cap, a field removed
 * from the form stops being writable, and a select can only ever be set to one
 * of the values it offers. The alternative was a second copy of thirty field
 * names and thirty numbers, which is the copy that goes stale.
 *
 * A PRICE BOX THAT DOES NOT HOLD A NUMBER IS REFUSED, NOT STORED. `Number("")`
 * is 0 in JavaScript and `Pricing\Num` faithfully ports that, so an empty box
 * must be OMITTED (null means "not offered"; 0 means "free") - and a box holding
 * "abc" must not become NAN, which is not JSON and would fail the write. Either
 * way the value already stored is kept and `kept` counts it, so the screen can
 * say how many boxes did not save instead of losing them in silence.
 */
final class DraftForm
{
    /** The longest a free-text field may be when its own form does not say. */
    private const TEXT_MAX = 8000;

    /** A destination name, as the table's own maxlength has it. */
    private const NAME_MAX = 120;

    /** "3-5" and nothing longer. */
    private const DAYS_MAX = 20;

    /** How many per-kilogram boxes one service on one row may post. */
    private const GRID_MAX = 200;

    /** A review author's name, as `Schema::CAPS['review']['author']` has it. */
    private const REVIEW_AUTHOR_MAX = 120;

    /** A review's text, as `Schema::CAPS['review']['text']` has it. */
    private const REVIEW_TEXT_MAX = 1000;

    /** A review's free-text date, as `Schema::CAPS['review']['date']` has it. */
    private const REVIEW_DATE_MAX = 60;

    /**
     * The heaviest kilogram box the grid may name.
     *
     * 999 and not 1000, to agree with `Schema::GRID_KEY` (`^\d{1,3}$`). A
     * "1000" box used to be accepted here and refused by the schema, so it
     * saved into the draft and then made the whole document unpublishable -
     * the owner would have met it as "That could not be published", about a
     * box he could not find. A box this parser will not store is one the
     * publisher would not have taken either.
     */
    private const GRID_KG_MAX = 999;

    /**
     * @param array<string,mixed> $document  the patched document, ready for Migrate
     * @param int                 $kept      values that were refused, and the stored ones kept
     * @param bool                $empty     the post carried none of the editor's field groups at all
     * @param bool                $truncated PHP stopped reading the body part-way; see `wasTruncated()`
     * @param string|null         $revision  the draft fingerprint the editor was RENDERED with, or null
     *                                       when this form does not carry the field at all - see
     *                                       `revision()`
     * @param int|null            $base      the published version the editor was OPENED at, or null
     *                                       when the form does not carry it - see `base()`
     * @param int                 $unnamed   rows whose name box arrived empty; the stored name was kept.
     *                                       NOT part of `kept`, which the screen reads as "a box that did
     *                                       not hold a number" - see `destination()`
     */
    private function __construct(
        public readonly array $document,
        public readonly int $kept,
        public readonly bool $empty,
        public readonly bool $truncated = false,
        public readonly ?string $revision = null,
        public readonly ?int $base = null,
        public readonly int $unnamed = 0,
    ) {
    }

    /** Read the post against the draft it was rendered from. */
    public static function of(SiteData $draft, Request $request): self
    {
        $document = $draft->toArray();
        $post     = $request->post;

        $dest     = is_array($post['dest'] ?? null) ? $post['dest'] : null;
        $company  = is_array($post['company'] ?? null) ? $post['company'] : null;
        $settings = is_array($post['settings'] ?? null) ? $post['settings'] : null;
        $content  = is_array($post['content'] ?? null) ? $post['content'] : null;
        $promo    = is_array($post['promo'] ?? null) ? $post['promo'] : null;
        $reviews  = is_array($post['reviews'] ?? null) ? $post['reviews'] : null;

        $kept    = 0;
        $unnamed = 0;

        if ($dest !== null) {
            $document['destinations'] = self::destinations(
                is_array($document['destinations'] ?? null) ? $document['destinations'] : [],
                $dest,
                $kept,
                $unnamed,
            );
        }

        // The three field groups are described by the forms themselves; see the
        // class note. `settings` is read before `content` only because that is
        // the order they appear on the page.
        $fields = self::fields($draft);

        foreach (['company' => $company, 'settings' => $settings, 'content' => $content] as $group => $posted) {
            if ($posted === null) {
                continue;
            }

            $document[$group] = self::group(
                $group,
                is_array($document[$group] ?? null) ? $document[$group] : [],
                $posted,
                $fields,
                $kept,
            );
        }

        // The promo popup is patched from its own posted fields. It is NOT one
        // of the form-defined groups above (Settings and Website pages), so it
        // reads with its own five fixed fields rather than through `fields()`.
        if ($promo !== null) {
            $document['promo'] = self::promo(
                is_array($document['promo'] ?? null) ? $document['promo'] : [],
                $promo,
            );
        }

        // The reviews are REBUILT from their posted rows, not patched: a list
        // the owner grows and prunes cannot be merged field by field. See
        // `reviews()`.
        if ($reviews !== null) {
            $document['reviews'] = self::reviews($reviews);
        }

        return new self(
            document: $document,
            kept: $kept,
            empty: $dest === null && $company === null && $settings === null && $content === null
                && $promo === null && $reviews === null,
            truncated: self::wasTruncated($request),
            revision: self::revision($request),
            base: self::base($request),
            unnamed: $unnamed,
        );
    }

    /**
     * WHICH DRAFT THIS FORM WAS RENDERED FROM.
     *
     * `Domain\SiteDoc\Draft` computes a short fingerprint of the stored row -
     * the document, when it was written and by whom - and `Repo::saveDraft()`
     * refuses a write when the row no longer has the fingerprint it is handed.
     * That is what stops two admins with the editor open from taking turns
     * overwriting each other in silence, and it only works if the editor hands
     * back the fingerprint IT was drawn with rather than re-reading the current
     * one.
     *
     * NULL AND "" ARE DIFFERENT ANSWERS, and the difference is this method's
     * whole reason to exist. `Repo::saveDraft()` reads null as "there is no
     * screen behind this write" (a seed, a script, a restore) and skips the
     * check; it reads a string as a claim about the stored row, and an empty
     * string matches nothing, so it fails closed. A form that never carried the
     * field must get the first - last-writer-wins, exactly as before - and a
     * form whose hidden field arrived empty must get the second. `$request->post()`
     * cannot tell them apart, because it answers "" for both, so the raw array
     * is asked instead.
     *
     * Public because Restore posts the same hidden field from its own small
     * form and has to read it by exactly these rules - a second copy of them
     * is the copy that drifts.
     */
    public static function revision(Request $request): ?string
    {
        if (!array_key_exists('revision', $request->post)) {
            return null;
        }

        $value = $request->post['revision'];

        // An array or an object where a fingerprint should be is not a
        // fingerprint; "" is the value that fails closed, which is the right
        // answer to a field somebody has taken apart.
        return is_scalar($value) ? Str::limit(trim((string) $value), 64) : '';
    }

    /**
     * WHICH PUBLISHED VERSION THE EDITOR WAS OPENED AT.
     *
     * The publish is refused when the live version has moved since the document
     * on the screen was reviewed (`Repo::publishVersion()`'s `expectedBase`).
     * For that refusal to be reachable the number has to be the one the editor
     * was OPENED at and then carried, unchanged, through every save and across
     * the redirect to the review screen - a form that prints the CURRENT live
     * version on every render always agrees with it, and the guard can never
     * fire.
     *
     * Null when the form does not carry it, so a caller can tell "this post has
     * no opinion" from "this post says version 0", which is what a site with
     * nothing published yet legitimately says.
     */
    private static function base(Request $request): ?int
    {
        if (!array_key_exists('expectedBase', $request->post)) {
            return null;
        }

        $value = $request->post['expectedBase'];

        if (!is_scalar($value) || preg_match('/^\d{1,10}$/', trim((string) $value)) !== 1) {
            return null;
        }

        return (int) trim((string) $value);
    }

    /**
     * DID THE WHOLE FORM ARRIVE?
     *
     * PHP stops parsing a request body once it has read `max_input_vars`
     * variables. It does not fail and it does not tell the script: `$_POST`
     * simply ends early. So a post big enough to cross that line saves its
     * first thousand fields and drops the rest, and the owner is shown "Saved"
     * over prices that did not save.
     *
     * MEASURED, NOT ASSUMED: the editor used to be ONE form carrying the entire
     * document, and ten countries priced per kilogram to a 25 kg threshold
     * already posted about seven hundred fields. Adding Express Saver as a third
     * service put 1009 on the wire against a limit of 1000, and the rates editor
     * could not save at all. The fix is upstream of this guard - a country is
     * its own form now, which is about ninety variables whatever the document
     * grows to - but the guard stays exactly where it is: it is the reason that
     * was caught as a refusal instead of as a price that silently went missing,
     * and a cargo threshold of 300 kg would walk into the same wall tomorrow.
     * The deploy sample sets exactly 1000 and staging 2000; neither is trusted.
     *
     * THE BODY IS COMPARED WITH WHAT PHP MADE OF IT, and not with the limit.
     * `max_input_vars` counts intermediate array dimensions as well as values,
     * so "PHP parsed exactly 1000" is never true of `$_POST` - the body that
     * proved this was cut off at 998 values against a limit of 1000, and a test
     * that waited for the number to reach the limit would never have fired.
     *
     * Counting names rather than pairs is what keeps it from crying wolf. A body
     * legitimately holds MORE pairs than `$_POST` holds values: a checkbox posts
     * a hidden "0" ahead of its own "1" and PHP keeps the last of a repeated
     * name, so ten countries mean ten extra pairs in a submit that arrived
     * whole. Distinct names cannot outnumber the values PHP built from them
     * unless PHP stopped reading - and `name[]`, which appends rather than
     * replaces, is counted per occurrence for the same reason.
     *
     * `php://input` is readable for an urlencoded body, which is the only kind
     * the editor sends; a multipart body cannot be re-read and is left alone
     * rather than guessed at.
     */
    private static function wasTruncated(Request $request): bool
    {
        if ((int) ini_get('max_input_vars') <= 0) {
            return false;
        }

        if (!str_contains(strtolower($request->header('content-type')), 'application/x-www-form-urlencoded')) {
            return false;
        }

        $body = @file_get_contents('php://input');

        if (!is_string($body) || $body === '') {
            return false;
        }

        $parsed = self::countValues($request->post);

        // The cheap half: pairs are an upper bound on names, so a body with no
        // more pairs than PHP made values cannot be short of any.
        if (substr_count($body, '&') + 1 <= $parsed) {
            return false;
        }

        return self::countNames($body) > $parsed;
    }

    /**
     * How many values an urlencoded body asks for: one per distinct name, plus
     * one per occurrence of an appending `name[]`.
     */
    private static function countNames(string $body): int
    {
        $seen    = [];
        $appends = 0;

        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = strpos($pair, '=');
            $name   = urldecode($equals === false ? $pair : substr($pair, 0, $equals));

            if ($name === '') {
                continue;
            }

            if (str_ends_with($name, '[]')) {
                $appends++;
                continue;
            }

            $seen[$name] = true;
        }

        return count($seen) + $appends;
    }

    /**
     * How many variables PHP made of the body - the same thing `max_input_vars`
     * counts, which is every scalar leaf rather than every top-level key.
     *
     * @param array<array-key,mixed> $values
     */
    private static function countValues(array $values): int
    {
        $n = 0;

        foreach ($values as $value) {
            $n += is_array($value) ? self::countValues($value) : 1;
        }

        return $n;
    }

    /** The patched document as the domain layer reads it. */
    public function site(): SiteData
    {
        return Migrate::migrate($this->document);
    }

    /* --------------------------------------------------------- destinations */

    /**
     * Update the rows the form posted, in the order it posted them.
     *
     * @param  list<mixed>         $current the draft's destinations, as stored
     * @param  array<mixed,mixed>  $posted  `dest[<id>][...]`
     * @return list<array<string,mixed>>
     */
    private static function destinations(array $current, array $posted, int &$kept, int &$unnamed): array
    {
        $rows = [];

        foreach ($current as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id    = (string) ($row['id'] ?? '');
            $entry = $id !== '' && is_array($posted[$id] ?? null) ? $posted[$id] : null;

            $rows[] = [
                'row'      => $entry === null ? $row : self::destination($row, $entry, $kept, $unnamed),
                'position' => $entry === null || !isset($entry['position']) || !is_scalar($entry['position'])
                    ? $index
                    : (int) $entry['position'],
                'index'    => $index,
            ];
        }

        // Stable: two rows claiming the same position keep the order the draft
        // had them in, so a duplicated position cannot shuffle the table.
        usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position'] ?: $a['index'] <=> $b['index']);

        $out = [];
        foreach ($rows as $row) {
            /** @var array<string,mixed> $document */
            $document = $row['row'];
            $out[]    = $document;
        }

        return $out;
    }

    /**
     * One row: its name, whether the site shows it, and its rates.
     *
     * @param  array<string,mixed> $row
     * @param  array<mixed,mixed>  $entry
     * @return array<string,mixed>
     */
    private static function destination(array $row, array $entry, int &$kept, int &$unnamed): array
    {
        $name = isset($entry['name']) && is_scalar($entry['name'])
            ? Str::limit(Str::squish((string) $entry['name']), self::NAME_MAX)
            : '';

        if ($name === '') {
            // A country with no name renders as a blank row on the website and
            // cannot be searched for. The stored name stays.
            //
            // COUNTED ON ITS OWN, NOT IN `kept`. It used to be `$kept++`, and
            // `kept` is read out as "N boxes did not hold a number" - so the
            // owner who added a country and keyed its prices before naming it
            // was told on every save that a price had not saved, although all
            // of them had, and re-keyed correct numbers (2026-10-25).
            $unnamed++;
        } else {
            $row['name'] = $name;
        }

        if (isset($entry['active'])) {
            $row['active'] = is_scalar($entry['active'])
                && !in_array((string) $entry['active'], ['', '0', 'off', 'false'], true);
        }

        if (is_array($entry['rates'] ?? null)) {
            $rates = is_array($row['rates'] ?? null) ? $row['rates'] : [];

            foreach ($entry['rates'] as $serviceId => $posted) {
                $key = (string) $serviceId;

                // A rate for a service the row does not carry is still accepted -
                // that is how a price is first entered for a service - but only
                // for a service the document has, which `Migrate` enforces on the
                // way back in.
                if (is_array($posted)) {
                    $rates[$key] = self::rate(is_array($rates[$key] ?? null) ? $rates[$key] : [], $posted, $kept);
                }
            }

            $row['rates'] = $rates;
        }

        return $row;
    }

    /**
     * One service's prices on one row.
     *
     * @param  array<string,mixed> $rate
     * @param  array<mixed,mixed>  $posted
     * @return array<string,mixed>
     */
    private static function rate(array $rate, array $posted, int &$kept): array
    {
        // No `doc`: the flat document price left the product on 2026-09-27
        // (owner), so a posted one is not read and a stored one is dropped here
        // rather than surviving a save made against an un-migrated draft.
        unset($rate['doc']);

        foreach (['first', 'addl'] as $field) {
            if (!array_key_exists($field, $posted)) {
                continue;
            }

            $value = self::price($posted[$field], $kept, array_key_exists($field, $rate) ? $rate[$field] : null);

            if ($value === null) {
                unset($rate[$field]);
            } else {
                $rate[$field] = $value;
            }
        }

        if (array_key_exists('days', $posted) && is_scalar($posted['days'])) {
            // "7-10" is stored as "7" en dash "10": see `Csv::days()` - the
            // hyphen is what turns a transit time into a date in Excel.
            $days = Str::limit(Csv::days((string) $posted['days']), self::DAYS_MAX);

            if ($days === '') {
                unset($rate['days']);
            } else {
                $rate['days'] = $days;
            }
        }

        // Only when the form posted a grid at all: in slab mode it posts none,
        // and a stored grid must survive a save made in slab mode.
        if (is_array($posted['grid'] ?? null)) {
            $rate['grid'] = self::grid(is_array($rate['grid'] ?? null) ? $rate['grid'] : [], $posted['grid'], $kept);
        }

        return $rate;
    }

    /**
     * The per-kilogram boxes.
     *
     * @param  array<array-key,mixed> $stored
     * @param  array<mixed,mixed>     $posted
     * @return array<string,float>
     */
    private static function grid(array $stored, array $posted, int &$kept): array
    {
        $out   = [];
        $boxes = 0;

        foreach ($posted as $kg => $value) {
            if (++$boxes > self::GRID_MAX) {
                break;
            }

            $weight = is_scalar($kg) ? (string) $kg : '';

            if (preg_match('/^[1-9][0-9]{0,3}$/', $weight) !== 1 || (int) $weight > self::GRID_KG_MAX) {
                continue;
            }

            $price = self::price($value, $kept, $stored[$weight] ?? null);

            if ($price !== null) {
                $out[$weight] = $price;
            }
        }

        return $out;
    }

    /**
     * A price box: a number, or null for "leave it out".
     *
     * Blank means "not offered" and returns null. Anything present that is not a
     * number keeps whatever was stored (returned as it was) and counts towards
     * `kept`, because storing NAN is not possible and storing 0 would tell the
     * owner he charges nothing.
     */
    private static function price(mixed $value, int &$kept, mixed $stored): ?float
    {
        if (!is_scalar($value)) {
            return self::storedPrice($stored, $kept);
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        // Deliberately stricter than JavaScript's Number(): "1e999" is a finite
        // literal to is_numeric() but INF as a float, and a price the owner
        // cannot have meant.
        if (!is_numeric($text)) {
            return self::storedPrice($stored, $kept);
        }

        $number = (float) $text;

        if (!is_finite($number) || $number < 0) {
            return self::storedPrice($stored, $kept);
        }

        return $number;
    }

    /** What was already there, when the posted value could not be used. */
    private static function storedPrice(mixed $stored, int &$kept): ?float
    {
        $kept++;

        if (!is_scalar($stored)) {
            return null;
        }

        $number = (float) $stored;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    /* ------------------------------------------- company, settings, content */

    /**
     * One field group, patched field by field against the form's own definition.
     *
     * @param  array<array-key,mixed>                                                            $stored
     * @param  array<mixed,mixed>                                                                $posted
     * @param  array<string,array{type:string,max:int,options:array<string,string>}>             $fields
     * @return array<string,mixed>
     */
    private static function group(string $group, array $stored, array $posted, array $fields, int &$kept): array
    {
        /** @var array<string,mixed> $out */
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        foreach ($posted as $key => $value) {
            $name  = is_scalar($key) ? (string) $key : '';
            $field = $fields[$group . '[' . $name . ']'] ?? null;

            // Not a field this form draws: ignored, so nothing can be written
            // into the document by adding an input to a copy of the page.
            if ($name === '' || $field === null) {
                continue;
            }

            if ($field['type'] === Field::CHECKBOX) {
                $out[$name] = is_scalar($value) && !in_array((string) $value, ['', '0', 'off', 'false'], true);
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $text = (string) $value;

            if ($field['type'] === Field::SELECT) {
                if (isset($field['options'][$text])) {
                    $out[$name] = $text;
                } else {
                    $kept++;
                }
                continue;
            }

            if ($field['type'] === Field::NUMBER) {
                $number = self::setting($text, $name, $kept);

                if ($number !== false) {
                    $out[$name] = $number;
                }
                continue;
            }

            $max = $field['max'] > 0 ? $field['max'] : self::TEXT_MAX;

            // Newlines are content in a textarea (one FAQ per line) and noise in
            // a single-line box, where a pasted line break would become part of
            // a company name.
            $out[$name] = $field['type'] === Field::TEXTAREA
                ? Str::limit(str_replace("\r\n", "\n", $text), $max)
                : Str::limit(Str::squish($text), $max);
        }

        return $out;
    }

    /**
     * The promo popup block, patched from its own posted fields.
     *
     * Five fixed fields, capped exactly where `Schema` caps them so a save can
     * never produce a promo the publisher would then refuse: an enable checkbox
     * and four single-line texts. The checkbox is read the way a country's
     * "Show on site" is - a hidden "0" companion means an unticked box arrives
     * as "0" rather than as nothing, so "turned it off" is not read as "not on
     * this form". A field the form did not post keeps whatever was stored.
     *
     * @param  array<array-key,mixed> $stored
     * @param  array<mixed,mixed>     $posted
     * @return array<string,mixed>
     */
    private static function promo(array $stored, array $posted): array
    {
        /** @var array<string,mixed> $out */
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        $out['enabled'] = isset($posted['enabled'])
            && is_scalar($posted['enabled'])
            && !in_array((string) $posted['enabled'], ['', '0', 'off', 'false'], true);

        foreach (['title' => 120, 'message' => 300, 'ctaLabel' => 60, 'ctaHref' => 300] as $field => $max) {
            if (array_key_exists($field, $posted) && is_scalar($posted[$field])) {
                $out[$field] = Str::limit(Str::squish((string) $posted[$field]), $max);
            }
        }

        return $out;
    }

    /**
     * The customer reviews, REBUILT from the editor's own posted rows.
     *
     * Unlike the single promo block, reviews are a list the owner grows and
     * prunes, so this does not patch a stored block - it builds the list fresh
     * from what the form posted, row by row, in the order it posted them. Two
     * kinds of row yield nothing: one whose "Remove this review" box is ticked,
     * and one left entirely blank - an unused "add" slot, or one the owner
     * cleared to delete it. Everything else becomes a review in Review::FIELDS
     * order (author, rating, text, date, show) so the block round-trips byte for
     * byte like the rest of the document.
     *
     * Each value is capped exactly where `Schema` caps it and the star count is
     * pinned to a whole 1 - 5, so a save can never produce a reviews block the
     * publisher would then refuse; the list itself is capped at
     * `Schema::MAX_REVIEWS` for the same reason. The author and date are
     * single-line (squished); the text is a small textarea, so its line breaks
     * are kept the way a FAQ answer's are.
     *
     * @param  array<mixed,mixed> $posted reviews[<i>][author|rating|text|date|show|remove]
     * @return list<array<string,bool|int|string>>
     */
    private static function reviews(array $posted): array
    {
        $out = [];

        foreach ($posted as $row) {
            if (count($out) >= Schema::MAX_REVIEWS) {
                break;
            }

            if (!is_array($row)) {
                continue;
            }

            // "Remove this review": a ticked box drops the row outright.
            if (isset($row['remove']) && is_scalar($row['remove'])
                && !in_array((string) $row['remove'], ['', '0', 'off', 'false'], true)) {
                continue;
            }

            $author = isset($row['author']) && is_scalar($row['author'])
                ? Str::limit(Str::squish((string) $row['author']), self::REVIEW_AUTHOR_MAX)
                : '';

            $text = isset($row['text']) && is_scalar($row['text'])
                ? Str::limit(str_replace("\r\n", "\n", (string) $row['text']), self::REVIEW_TEXT_MAX)
                : '';

            // A row with no name and no words is an empty slot, not a review.
            if ($author === '' && trim($text) === '') {
                continue;
            }

            $date = isset($row['date']) && is_scalar($row['date'])
                ? Str::limit(Str::squish((string) $row['date']), self::REVIEW_DATE_MAX)
                : '';

            $out[] = [
                'author' => $author,
                'rating' => self::stars($row['rating'] ?? null),
                'text'   => $text,
                'date'   => $date,
                // A hidden "0" companion means an unticked box arrives as "0",
                // so "hid it" is not read as "not on this form"; the default on
                // an added row is checked, matching Review's show default.
                'show'   => isset($row['show']) && is_scalar($row['show'])
                    && !in_array((string) $row['show'], ['', '0', 'off', 'false'], true),
            ];
        }

        return $out;
    }

    /**
     * A whole star count pinned to 1 - 5, matching `Review::clampRating`: a
     * value that is not a number falls to five rather than to zero, a fractional
     * one rounds to a whole star, and anything outside the range is clamped in.
     */
    private static function stars(mixed $value): int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return 5;
        }

        $number = (float) $value;

        if (!is_finite($number)) {
            return 5;
        }

        return max(1, min(5, (int) round($number)));
    }

    /**
     * A numeric setting: a number, null for the one field where blank means
     * "none", or false for "keep what is stored".
     *
     * A cleared box is 0 for every other numeric setting, exactly as the React
     * form stored it (`Number("")` is 0) - and a 0 in the wrong place is refused
     * by `Validate::errors()` at publish time rather than being guessed at here.
     */
    private static function setting(string $text, string $name, int &$kept): float|null|false
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $name === 'cutoffHour' ? null : 0.0;
        }

        if (!is_numeric($trimmed)) {
            $kept++;

            return false;
        }

        $number = (float) $trimmed;

        if (!is_finite($number)) {
            $kept++;

            return false;
        }

        return $number;
    }

    /**
     * Every field the Settings and Website pages sections draw, by posted name.
     *
     * @return array<string,array{type:string,max:int,options:array<string,string>}>
     */
    private static function fields(SiteData $draft): array
    {
        $site = Site::fromArray($draft->toArray());

        $out = [];

        foreach ([SettingsFields::groups($site), ContentFields::groups($site)] as $groups) {
            foreach ($groups as $group) {
                foreach ($group['rows'] as $row) {
                    foreach ($row['fields'] as $field) {
                        $out[$field->name] = [
                            'type'    => $field->type,
                            'max'     => $field->maxLength,
                            'options' => $field->options,
                        ];
                    }
                }
            }
        }

        return $out;
    }
}
