<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Core\Money;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Core\ValueTooLong;
use Speedat\Domain\Pricing\CivilDate;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\Draft;
use Speedat\Domain\SiteDoc\DraftConflict;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\PublishConflict;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\RollbackRefused;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Domain\SiteDoc\Validate;
use Speedat\View\Admin\Csv;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Site;
use Speedat\View\Admin\TestPricePanel;

/**
 * The rates editor's actions: save the draft, change the table, review, publish,
 * restore.
 *
 * ONE SHAPE FOR ALL OF THEM. Every button in the table submits THE WHOLE EDITOR
 * FORM to its own action (`formaction`), so each of these starts by reading the
 * posted draft and saving it - which is why pressing "Add country" with a
 * half-typed price in a box keeps the price. Then it makes its one change, and
 * then it redirects to a GET that renders the editor from the database. A reload
 * of that GET is a reload, not a second publish.
 *
 * WHAT THE OWNER MAY DO AND WHAT ONLY AN OWNER MAY DO. Everything here is an
 * editor's work except publish, which `Guard::owner()` refuses for anybody else
 * server-side; the template hides the button as a courtesy, not as the control.
 * `Domain\SiteDoc\Repo` takes an `expectedBase` and refuses a publish on top of
 * a version that moved underneath the review - the owner is shown the same
 * sentence the TypeScript showed, and nothing is written.
 *
 * NOTHING HERE READS A SPREADSHEET. The rate-sheet feature was removed from the
 * product on 2026-09-27: the CSV route below is an EXPORT, a GET that streams a
 * file, and there is no counterpart that reads one back.
 */
final class RatesController
{
    /** A test price is one shipment of one line; the panel has one weight box. */
    private const TEST_MAX_PIECES = 200;

    /* --------------------------------------------------------------- screens */

    /** GET /admin */
    public function editor(App $app, AdminUser $admin): Response
    {
        return EditorPage::render($app, $admin, false);
    }

    /**
     * GET /admin/rates/review - the editor with the review card under the table.
     *
     * It is a GET as well as the target of the publish bar's POST, because
     * "review what is about to go live" is a page somebody links to, reloads and
     * comes back to after a refused publish. The card is rebuilt from the draft
     * every time, so it can never describe a document that is no longer there.
     */
    public function review(App $app, AdminUser $admin): Response
    {
        return EditorPage::render($app, $admin, true);
    }

    /**
     * POST /admin/rates/review - "Review and publish" in the sticky bar.
     *
     * The button submits THE EDITOR ITSELF, so whatever is in the boxes is saved
     * and then reviewed: there is no path where the owner reviews one document
     * and publishes another. Then it redirects to the GET, so a reload of the
     * review screen re-reads the draft instead of re-saving a stale form.
     */
    public function reviewAfterSave(App $app, AdminUser $admin): Response
    {
        $draft = Repo::getDraft();
        $form  = DraftForm::of($draft->data, $app->request);

        // Reviewing half a document would be worse than not saving it: the diff
        // would describe prices nobody typed. Nothing is written, and the owner
        // goes back to the editor with the reason.
        if ($form->truncated) {
            Log::warn('draft_truncated', ['by' => $admin->email, 'action' => 'review', 'limit' => (int) ini_get('max_input_vars')]);

            return $this->toEditor($app, ['err' => 'toobig']);
        }

        if (!$form->empty) {
            try {
                $this->store($app, $admin, $form->site(), 'draft_saved', ['kept' => $form->kept], $draft->data, $form->revision, $form->document);
            } catch (ValueTooLong) {
                return $this->toEditor($app, ['err' => 'toolong']);
            } catch (BadDocument) {
                return $this->toEditor($app, ['err' => 'badshape'], '', $form->base);
            } catch (DraftConflict $e) {
                return $this->refuseStaleDraft($app, $admin, 'review', $e, $form->base);
            }
        }

        return $this->toReview($app, ($form->kept > 0 ? ['kept' => $form->kept] : []) + ['noname' => $form->unnamed > 0], $form->base);
    }

    /**
     * GET /admin/rates/export.csv - the rates as a file.
     *
     * IT EXPORTS WHAT THE SITE IS SERVING, NOT WHAT IS IN THE EDITOR. It used
     * to hand out the DRAFT under a filename that said "rates" and a
     * `Content-Disposition` that said nothing else, so the file a carrier or a
     * customer was sent could be a page of half-typed prices nobody had
     * published - indistinguishable, once it left the building, from the rates
     * the website was quoting. A file called "rates" is the price list people
     * will hold the office to, and the only document that has been agreed to is
     * the published one.
     *
     * THE DRAFT IS STILL EXPORTABLE, at `?draft=1`, and it says so twice: the
     * word "draft" is in the filename and the first line of the file states, in
     * words, that it is unpublished and which version it was branched from. A
     * draft that leaves the building has to introduce itself.
     *
     * THE FIRST LINE IS A COMMENT ROW and it is deliberately one cell with no
     * commas in it, so opening the file in Excel puts the sentence in A1 and
     * does not shift the header row that follows. It starts with `#`, which is
     * not one of the four characters Excel treats as a formula.
     *
     * THE FILE STARTS WITH A UTF-8 BOM (`Csv::BOM`), because Windows Excel
     * opens a double-clicked .csv as cp1252 without one and every en dash in
     * a days cell, and the u-umlaut in "Türkiye", came out as mojibake in the
     * file the owner keeps. The BOM is on the download only; the copy button's
     * text is the same `Csv::document()` without it.
     *
     * `Content-Disposition: attachment` and the formula guard are both in
     * `View\Admin\Csv`: a cell starting with `= + - @` is prefixed with an
     * apostrophe, because Excel executes such a cell and a carrier who names a
     * country "=cmd|..." should not be running commands in the owner's
     * spreadsheet.
     */
    public function exportCsv(App $app, AdminUser $admin): Response
    {
        $live  = Repo::getLatestVersion() ?? PublishedVersion::sample();
        $draft = $app->request->query('draft', '', 4) === '1';

        if ($draft) {
            $base    = Repo::getDraft();
            $site    = Site::fromArray($base->data->toArray());
            $marker  = 'draft-from-v' . $base->baseVersion;
            $comment = Csv::draftComment($base->baseVersion, Clock::utcNow());
        } else {
            $site    = Site::fromArray($live->data->toArray() + [
                'version'     => $live->version,
                'publishedAt' => $live->publishedAt,
            ]);
            $marker  = 'v' . $live->version;
            $comment = Csv::liveComment($live->version, $live->publishedAt, Clock::utcNow());
        }

        Audit::log($admin->email, 'rates_exported', [
            'source'  => $draft ? 'draft' : 'live',
            'version' => $draft ? 0 : $live->version,
            'rows'    => count($site->destinations()),
        ], $app->request);

        return Response::text(Csv::BOM . Csv::document($comment, $site))
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . self::exportName($site, $marker) . '"')
            ->noStore();
    }

    /** `speedat-…-rates.csv` with the version (or the word draft) before the extension. */
    private static function exportName(Site $site, string $marker): string
    {
        $name = Csv::filename($site);

        return (str_ends_with($name, '.csv') ? substr($name, 0, -4) : $name) . '-' . $marker . '.csv';
    }

    /* ----------------------------------------------------------------- saving */

    /**
     * POST /admin/rates/save - the autosave, and the no-JS "Save draft".
     *
     * admin.js posts the same fields to the same URL with
     * `X-Requested-With: fetch` and expects `{"ok":true,"savedAt":…,"changes":…}`;
     * a plain submit gets a redirect. One code path, one parser, one write - the
     * contract is written out in public/assets/js/admin.js and docs/assets.md.
     */
    public function save(App $app, AdminUser $admin): Response
    {
        $draft = Repo::getDraft();
        $form  = DraftForm::of($draft->data, $app->request);

        if ($form->empty) {
            // `Notice::text()` and not a sentence written here: the redirect
            // path reads the words for `err=nothing` out of that one file, and
            // one refusal described two ways is the thing that class exists to
            // prevent.
            return $this->answer($app, ['err' => 'nothing'], Notice::text('nothing'), $form->base);
        }

        // NOTHING IS WRITTEN FROM HALF A FORM. `DraftForm::wasTruncated()` has
        // the reasoning: PHP stops reading a body at `max_input_vars` without
        // telling the script, so saving what did arrive would write the first
        // thousand fields over the rest and answer "Saved". Refusing leaves the
        // draft exactly as it was and says what happened.
        if ($form->truncated) {
            Log::warn('draft_truncated', ['by' => $admin->email, 'limit' => (int) ini_get('max_input_vars')]);

            return $this->answer($app, ['err' => 'toobig'], Notice::text('toobig'), $form->base);
        }

        try {
            $saved = $this->store($app, $admin, $form->site(), 'draft_saved', ['kept' => $form->kept], $draft->data, $form->revision, $form->document);
        } catch (ValueTooLong $e) {
            return $this->answer($app, ['err' => 'toolong'], $e->getMessage(), $form->base);
        } catch (BadDocument) {
            return $this->answer($app, ['err' => 'badshape'], Notice::text('badshape'), $form->base);
        } catch (DraftConflict $e) {
            // Somebody else wrote the draft while this editor had it open.
            // NOTHING WAS WRITTEN - the repository threw inside the transaction
            // - and the autosave is told in the same words the redirect carries,
            // so the two paths cannot say different things about one refusal.
            //
            // WHO HAS IT travels with the refusal on the fetch path, because
            // that page never navigates and would otherwise have nothing but a
            // sentence about "somebody". The redirect path does not need it:
            // it re-renders the editor, which prints the same `editedBy()`
            // sentence from the row itself.
            Log::warn('draft_conflict', ['by' => $admin->email, 'action' => 'save', 'current' => $e->current->revision]);

            // The new fingerprint is deliberately NOT sent back. Handing it to
            // the page would let the next autosave adopt it and write straight
            // over the colleague's prices - the exact overwrite this guard
            // exists to stop, performed automatically. The only way forward is
            // to look at what they wrote, which is what Reload is for.
            return $this->answer($app, ['err' => 'draftmoved'], Notice::text('draftmoved'), $form->base, [
                'conflict' => true,
                'editedBy' => $e->current->editedBy(),
            ]);
        }

        return $this->answer($app, ['saved' => true, 'kept' => $form->kept, 'noname' => $form->unnamed > 0], '', $form->base, [], $saved);
    }

    /** POST /admin/rates/destination/add */
    public function addDestination(App $app, AdminUser $admin): Response
    {
        return $this->mutate($app, $admin, 'destination_added', static function (array $document): array {
            $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];

            if (count($destinations) >= Schema::MAX_DESTINATIONS) {
                return ['document' => $document, 'codes' => ['err' => 'toolong']];
            }

            $taken = [];
            foreach ($destinations as $row) {
                if (is_array($row) && isset($row['id']) && is_scalar($row['id'])) {
                    $taken[(string) $row['id']] = true;
                }
            }

            // A generated id, not a slug of the name: the row is created empty
            // and renaming a country must never change the id a booked shipment
            // and every logged quote already point at.
            do {
                $id = 'c' . bin2hex(random_bytes(4));
            } while (isset($taken[$id]));

            $destinations[] = ['id' => $id, 'name' => '', 'active' => false, 'rates' => []];

            $document['destinations'] = $destinations;

            return ['document' => $document, 'codes' => ['added' => true, 'detail' => ['id' => $id]]];
        });
    }

    /** POST /admin/rates/destination/remove - `remove=<destId>` */
    public function removeDestination(App $app, AdminUser $admin): Response
    {
        $target = $app->request->post('remove', '', 64);

        return $this->mutate($app, $admin, 'destination_removed', static function (array $document) use ($target): array {
            $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];

            $kept    = [];
            $removed = null;

            foreach ($destinations as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $target && $target !== '') {
                    $removed = $row;
                    continue;
                }
                $kept[] = $row;
            }

            if ($removed === null) {
                return ['document' => $document, 'codes' => ['err' => 'nodest']];
            }

            $document['destinations'] = $kept;

            return [
                'document' => $document,
                'codes'    => ['removed' => true, 'detail' => ['id' => $target, 'name' => (string) ($removed['name'] ?? '')]],
            ];
        });
    }

    /** POST /admin/rates/sort - `sort=az` */
    public function sort(App $app, AdminUser $admin): Response
    {
        if ($app->request->post('sort', '', 8) !== 'az') {
            return $this->mutate($app, $admin, 'destinations_sorted', static fn (array $d): array => ['document' => $d, 'codes' => []]);
        }

        return $this->mutate($app, $admin, 'destinations_sorted', static function (array $document): array {
            $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];

            // Str::nameKey, the same fold the search box and the table's own
            // data-name use, so "Türkiye" sorts where a reader expects it.
            usort($destinations, static function (mixed $a, mixed $b): int {
                $an = is_array($a) ? (string) ($a['name'] ?? '') : '';
                $bn = is_array($b) ? (string) ($b['name'] ?? '') : '';

                return strcmp(Str::nameKey($an), Str::nameKey($bn));
            });

            $document['destinations'] = $destinations;

            return ['document' => $document, 'codes' => ['sorted' => true]];
        });
    }

    /**
     * POST /admin/rates/row/undo - `undo=<destId>`
     *
     * Undo means "make this row what the website is serving": the live version's
     * row replaces the draft's, and a row the live version does not have at all
     * is removed. It touches one row and never the rest of the draft.
     */
    public function undoRow(App $app, AdminUser $admin): Response
    {
        $target = $app->request->post('undo', '', 64);
        $live   = Repo::getLatestVersion() ?? PublishedVersion::sample();

        $liveRow = null;
        foreach ($live->data->toArray()['destinations'] ?? [] as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $target) {
                $liveRow = $row;
                break;
            }
        }

        return $this->mutate($app, $admin, 'row_undone', static function (array $document) use ($target, $liveRow): array {
            if ($target === '') {
                return ['document' => $document, 'codes' => ['err' => 'nodest']];
            }

            $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];
            $out          = [];
            $found        = false;

            foreach ($destinations as $row) {
                if (!is_array($row) || (string) ($row['id'] ?? '') !== $target) {
                    $out[] = $row;
                    continue;
                }

                $found = true;

                if ($liveRow !== null) {
                    $out[] = $liveRow;
                }
            }

            if (!$found) {
                return ['document' => $document, 'codes' => ['err' => 'nodest']];
            }

            $document['destinations'] = $out;

            return [
                'document' => $document,
                'codes'    => ['undone' => true, 'detail' => ['id' => $target, 'removed' => $liveRow === null]],
            ];
        });
    }

    /**
     * POST /admin/rates/fill - `fill=blanks|all:<destId>:<serviceId>`
     *
     * Quick fill writes `start + i x step` into the per-kilogram boxes: "blanks"
     * only where there is no price yet, "all" over the lot. The two numbers come
     * from the row's own fill boxes, which are part of the editor form and are
     * therefore already in this post.
     */
    public function fill(App $app, AdminUser $admin): Response
    {
        $value = $app->request->post('fill', '', 200);
        $parts = explode(':', $value);

        $mode      = $parts[0] ?? '';
        $destId    = $parts[1] ?? '';
        $serviceId = $parts[2] ?? '';

        if (($mode !== 'blanks' && $mode !== 'all') || $destId === '' || $serviceId === '') {
            return $this->mutate($app, $admin, 'row_filled', static fn (array $d): array => ['document' => $d, 'codes' => ['err' => 'nodest']]);
        }

        $post  = $app->request->post;
        $rates = is_array($post['dest'][$destId]['rates'][$serviceId] ?? null)
            ? $post['dest'][$destId]['rates'][$serviceId]
            : [];

        $start = self::number($rates['fill']['start'] ?? null);
        $step  = self::number($rates['fill']['step'] ?? null);

        if ($start === null) {
            return $this->mutate(
                $app,
                $admin,
                'row_filled',
                static fn (array $d): array => ['document' => $d, 'codes' => ['err' => 'novalue', 'open' => $destId]],
            );
        }

        return $this->mutate($app, $admin, 'row_filled', static function (array $document) use ($destId, $serviceId, $mode, $start, $step): array {
            $settings = Migrate::migrate($document)->card->settings;
            $weights  = Engine::gridWeights($settings);

            if ($weights === []) {
                return ['document' => $document, 'codes' => ['err' => 'novalue', 'open' => $destId]];
            }

            $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];
            $filled       = 0;

            foreach ($destinations as $index => $row) {
                if (!is_array($row) || (string) ($row['id'] ?? '') !== $destId) {
                    continue;
                }

                $rate = is_array($row['rates'][$serviceId] ?? null) ? $row['rates'][$serviceId] : [];
                $grid = is_array($rate['grid'] ?? null) ? $rate['grid'] : [];

                foreach ($weights as $i => $kg) {
                    $key = (string) $kg;

                    if ($mode === 'blanks' && isset($grid[$key]) && is_numeric($grid[$key])) {
                        continue;
                    }

                    $grid[$key] = Money::roundTo($start + $i * ($step ?? 0.0), 1);
                    $filled++;
                }

                $rate['grid']                        = $grid;
                $row['rates'][$serviceId]            = $rate;
                $destinations[$index]                = $row;
                $document['destinations']            = $destinations;

                return [
                    'document' => $document,
                    'codes'    => ['filled' => true, 'open' => $destId, 'detail' => ['id' => $destId, 'service' => $serviceId, 'boxes' => $filled]],
                ];
            }

            return ['document' => $document, 'codes' => ['err' => 'nodest']];
        });
    }

    /**
     * POST /admin/rates/bulk - change many prices at once.
     *
     * Its own form, so the editor's fields are NOT in this post: the arithmetic
     * is applied to the stored draft. The two refusals are the React form's, and
     * admin.js repeats them in the browser only so the owner is not made to wait
     * for a round trip to be told he ticked nothing.
     */
    public function bulk(App $app, AdminUser $admin): Response
    {
        $services = $app->request->postList('services', 20);
        // 'doc' is no longer a bulk-editable field: the flat document price left
        // the product on 2026-09-27 (owner). A form that still posts it is
        // filtered down to the two that exist rather than refused.
        $fields   = array_values(array_intersect($app->request->postList('fields', 10), ['first', 'addl']));
        $mode     = $app->request->post('mode', 'pct', 8) === 'amt' ? 'amt' : 'pct';
        $value    = self::number($app->request->post('value', '', 24));
        $roundTo  = self::number($app->request->post('roundTo', '', 12));

        if ($services === [] || $fields === []) {
            // `#sec-bulk`: the refusal is printed in the Bulk adjust section,
            // below the table, and a page that opened at the top showed the
            // owner nothing at all - with JavaScript off, the only way he
            // would know is by scrolling to look.
            return $this->toEditor($app, ['bulkerr' => 'services'], '', null, 'sec-bulk');
        }

        if ($value === null || $value === 0.0) {
            return $this->toEditor($app, ['bulkerr' => 'value'], '', null, 'sec-bulk');
        }

        $round   = $roundTo !== null && $roundTo >= 1 ? $roundTo : 1.0;
        $changed = 0;

        $draft    = Repo::getDraft();
        $document = $draft->data->toArray();

        $destinations = is_array($document['destinations'] ?? null) ? $document['destinations'] : [];

        foreach ($destinations as $index => $row) {
            if (!is_array($row) || !is_array($row['rates'] ?? null)) {
                continue;
            }

            foreach ($row['rates'] as $serviceId => $rate) {
                if (!in_array((string) $serviceId, $services, true) || !is_array($rate)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (isset($rate[$field]) && is_numeric($rate[$field])) {
                        $rate[$field] = self::adjust((float) $rate[$field], $mode, $value, $round);
                        $changed++;
                    }
                }

                // The per-kilogram boxes are prices too. The React form changed
                // them with `first`, because a grid document has no first slab
                // and a bulk rise that skipped the grid would have changed
                // nothing at all on this site.
                if (in_array('first', $fields, true) && is_array($rate['grid'] ?? null)) {
                    foreach ($rate['grid'] as $kg => $price) {
                        if (is_numeric($price)) {
                            $rate['grid'][$kg] = self::adjust((float) $price, $mode, $value, $round);
                            $changed++;
                        }
                    }
                }

                $row['rates'][$serviceId] = $rate;
            }

            $destinations[$index] = $row;
        }

        $document['destinations'] = $destinations;

        try {
            $this->store($app, $admin, Migrate::migrate($document), 'rates_bulk_adjusted', [
                'services' => $services,
                'fields'   => $fields,
                'mode'     => $mode,
                'value'    => $value,
                'roundTo'  => $round,
                'changed'  => $changed,
            ], null, null, $document);
        } catch (ValueTooLong) {
            return $this->toEditor($app, ['err' => 'toolong']);
        } catch (BadDocument) {
            // "+900%" four times running takes a real price past the money cap.
            // Nothing is written and the draft is exactly as it was.
            return $this->toEditor($app, ['err' => 'badshape']);
        }

        // Same anchor: "275 prices changed in the editor" is in `#sec-bulk`,
        // and an owner who lands at the top and sees nothing happen is an
        // owner who presses +5% a second time.
        return $this->toEditor($app, ['bulk' => $changed], '', null, 'sec-bulk');
    }

    /**
     * POST /admin/rates/test - price a parcel against the DRAFT.
     *
     * It changes nothing, so it renders rather than redirecting: the owner has to
     * see the answer beside the numbers he typed. The price is computed by
     * `Domain\Pricing\Engine` - the same code that charges the customer - because
     * the point of the panel is to prove what the SERVER will do, and a browser
     * engine agreeing with itself proves nothing.
     */
    public function test(App $app, AdminUser $admin): Response
    {
        $draft = Repo::getDraft();
        $site  = Site::fromArray($draft->data->toArray());

        // No 'type': the flat document price left the product on 2026-09-27
        // (owner), so the panel proves one thing - the weight price.
        $input = [
            'destId'  => $app->request->post('destId', '', 64),
            'kg'      => $app->request->post('kg', '', 24),
            'pieces'  => $app->request->post('pieces', '1', 12),
            'L'       => $app->request->post('L', '', 24),
            'W'       => $app->request->post('W', '', 24),
            'H'       => $app->request->post('H', '', 24),
        ];

        $pieces = (int) (self::number($input['pieces']) ?? 1);
        $piece  = new Piece(
            kg: self::number($input['kg']) ?? NAN,
            qty: (float) max(1, min(self::TEST_MAX_PIECES, $pieces)),
            L: self::number($input['L']) ?? NAN,
            W: self::number($input['W']) ?? NAN,
            H: self::number($input['H']) ?? NAN,
        );

        $result = Engine::priceAll($site->card, $input['destId'], [$piece]);

        $now = Clock::now();

        return EditorPage::render($app, $admin, false, TestPricePanel::result(
            $site,
            $input,
            $result,
            CivilDate::fromDateTime($now),
            (int) $now->format('G'),
        ));
    }

    /* ------------------------------------------------------- draft lifecycle */

    /** POST /admin/rates/discard - throw the draft away and re-branch from the live version. */
    public function discard(App $app, AdminUser $admin): Response
    {
        Repo::discardDraft();

        // Reading it back re-creates it from the live version, so the editor the
        // owner lands on is never an empty page.
        Repo::getDraft();

        Audit::log($admin->email, 'draft_discarded', [], $app->request);

        return $this->toEditor($app, ['discarded' => true]);
    }

    /**
     * POST /admin/rates/restore - `version=<n>`
     *
     * It copies an old version's rates, settings and words into the DRAFT and
     * nothing more: the owner still reviews and publishes, which is why the
     * button says "Restore into editor". The audit line names the version, the
     * same detail `restoreVersionAction` recorded.
     *
     * IT REPLACES THE WHOLE DRAFT, so it is treated like the save it is.
     * `restoreform` carries the draft fingerprint the screen was drawn with
     * (the same hidden field every other draft form carries), and a draft a
     * colleague has saved since is refused with `draftmoved` instead of being
     * overwritten - it used to run with no fingerprint at all. The button
     * asks first when there are unpublished edits (`admin/partials/history`),
     * and the toast says when it replaced some, because "Version 10 is back
     * in the editor" read as if the thirty edits were still there beside it.
     */
    public function restore(App $app, AdminUser $admin): Response
    {
        $version = $app->request->postInt('version');

        if ($version < 1) {
            return $this->toEditor($app, ['err' => 'nodest']);
        }

        $live     = Repo::getLatestVersion();
        $replaced = $live !== null && Diff::of($live->data, Repo::getDraft()->data)->count > 0;

        try {
            $restored = Repo::restore($version, $admin->email, DraftForm::revision($app->request));
        } catch (DraftConflict $e) {
            return $this->refuseStaleDraft($app, $admin, 'restore', $e, null);
        }

        if ($restored === null) {
            return $this->toEditor($app, ['err' => 'nodest']);
        }

        Audit::log($admin->email, 'draft_restored', ['fromVersion' => $version, 'replacedEdits' => $replaced], $app->request);

        return $this->toEditor($app, ['restored' => $version, 'replaced' => $replaced]);
    }

    /**
     * POST /admin/rates/rollback - `version=<n>`, and `confirm=1` to mean it.
     *
     * RESTORE PUTS AN OLD VERSION IN THE EDITOR. THIS PUTS IT ON THE WEBSITE.
     * They are two answers to two different days: "let me look at what we used
     * to charge", and "that publish was wrong, take it off, now". Until this
     * existed the second one was four steps - restore, find the review screen,
     * read the diff, publish - performed by somebody who has just realised the
     * site is quoting the wrong prices, which is the worst moment to ask
     * anybody for four steps.
     *
     * IT IS TWO POSTS, NOT ONE. The first press writes NOTHING: it redirects
     * back to the editor with `?rollback=<n>`, and the screen asks the question
     * (`Notice::rollbackQuestion()`). Only a post carrying `confirm=1` performs
     * it. One press should not be able to change every price on the website, and
     * a confirmation that lives in a `window.confirm()` is a confirmation that
     * is gone the moment JavaScript is off.
     *
     * OWNER ONLY, AND THAT IS ENFORCED IN `app/routes.php` BY `Guard::owner()` -
     * session, CSRF and Origin, the per-admin write limit, then the role - not
     * by hiding the button. Publishing is owner-only; this publishes.
     *
     * EVERY REFUSAL LEAVES EVERYTHING AS IT WAS. `Repo::rollbackTo()` raises
     * before it writes anything, and each of its three reasons has its own
     * sentence, because "nothing happened" without a reason is the message that
     * makes somebody press the button again.
     */
    public function rollback(App $app, AdminUser $admin): Response
    {
        $version = $app->request->postInt('version');

        if ($version < 1) {
            return $this->toEditor($app, ['err' => 'norollback']);
        }

        // The question, not the act. Nothing is read and nothing is written:
        // whether that version exists is answered by the confirming post, so a
        // hand-typed version number cannot be used to find out which versions
        // are real without also carrying a valid CSRF token and the owner role.
        //
        // `Routes::rollbackAsk()` and not a bare `?rollback=`: the card is in
        // `#sec-history`, many screens below the top, and a redirect with no
        // fragment opened the page at the top - so during a wrong-price
        // incident the owner pressed Roll back and, as far as he could see,
        // nothing happened.
        if (!$app->request->postBool('confirm')) {
            return Response::redirect($app->request->basePath . Routes::rollbackAsk($version), 303)->noStore();
        }

        // THE LIVE VERSION THE QUESTION WAS ASKED ABOUT, from the card's own
        // hidden field. Absent (an older page, a script) means "whatever is
        // live now", the old behaviour; a value that is not a whole number is
        // treated as absent rather than as version 0, which would refuse.
        $expectedLive = $app->request->postInt('expectedLive', -1);

        try {
            $published = Repo::rollbackTo($version, $admin->email, $app->request, $expectedLive >= 0 ? $expectedLive : null);
        } catch (RollbackRefused $e) {
            Log::warn('rollback_refused', ['by' => $admin->email, 'version' => $version, 'reason' => $e->reason]);

            // `#reviewbox`, where `EditorPage::outcome()` prints the refusal -
            // the same place the success sentence lands.
            return $this->toEditor($app, ['err' => match ($e->reason) {
                RollbackRefused::NO_SUCH_VERSION => 'norollback',
                RollbackRefused::NO_CHANGE       => 'rollsame',
                default                          => 'rollstale',
            }], '', null, 'reviewbox');
        } catch (PublishConflict $e) {
            // Somebody published while the owner was reading the question, so
            // the version being undone is not the one that is live any more.
            // Refusing is the only safe answer: the roll-back was aimed at a
            // publish that is no longer the top of the pile.
            //
            // It used to redirect with `?conflict=N`, which only the REVIEW
            // card prints - so on the editor the refusal said nothing at all.
            // `rollmoved` is a sentence the editor prints, and the question is
            // drawn again against the version that is live now, so answering
            // it is one press rather than starting over.
            Log::warn('rollback_conflict', ['by' => $admin->email, 'version' => $version, 'live' => $e->live]);

            return $this->toEditor($app, ['err' => 'rollmoved', 'rollback' => $version], '', null, 'sec-history');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Log::exception('rollback_failed', $e, ['version' => $version]);

            return $this->toEditor($app, ['err' => 'publish'], '', null, 'reviewbox');
        }

        // `Repo::rollbackTo()` writes the audit line itself - who put the old
        // prices back, when, and whether prices were still held - so there is
        // deliberately none here. Two lines for one action is how a diary stops
        // being readable.
        // As the publish path: the sentence that says the website changed is
        // printed in `#reviewbox` at the foot of the editor, so the redirect
        // has to name it or the loudest act in the product reports silently.
        return $this->toEditor($app, ['rolledback' => $published->version], '', null, 'reviewbox');
    }

    /**
     * POST /admin/rates/publish - owner only.
     *
     * The order is the TypeScript's, and each step is a refusal the owner has to
     * be able to tell apart from the others:
     *
     *   1. `goLive` sets the document's own "rates are live" flag, so the sample
     *      notice comes off the website with the same publish.
     *   2. `Validate::errors()` blocks. A document that cannot price a parcel
     *      must not become the live one, whoever pressed the button.
     *   3. nothing to publish is refused rather than written: a version row that
     *      changes nothing is noise in the history.
     *   4. `expectedBase` is passed to the repository, which refuses inside the
     *      same transaction that would have written the row. The owner gets the
     *      exact sentence the TypeScript used.
     *   5. the audit line carries the version, the summary and the change list,
     *      so "what went live at 14:05" is answerable a year later.
     *
     * AND THE DRAFT HAS TO BE THE ONE THE CARD LISTED. `expectedBase` only
     * says the live version has not moved; the draft was re-read here and
     * published whatever it held, so a colleague's twenty autosaved prices
     * went live under a review card that listed three. The card now carries
     * the draft fingerprint it was drawn from (`revision`, the same
     * `data-revision` field admin.js keeps current after the owner's OWN
     * autosaves), and a different one is refused with `reviewmoved` and the
     * card redrawn. An absent field skips the check, as `DraftForm::revision()`
     * reads it everywhere else; `Repo::publishVersion()` asks again with the
     * draft row locked, so a save landing between here and the write is
     * caught too.
     */
    public function publish(App $app, AdminUser $admin): Response
    {
        $draft = Repo::getDraft();
        $live  = Repo::getLatestVersion() ?? PublishedVersion::sample();

        $revision = DraftForm::revision($app->request);

        if ($revision !== null && !hash_equals($draft->revision, $revision)) {
            Log::warn('publish_draft_moved', ['by' => $admin->email, 'current' => $draft->revision]);

            return $this->toReview($app, ['err' => 'reviewmoved']);
        }

        $goLive = $app->request->postBool('goLive');
        $resume = $app->request->postBool('resume');

        $document = $goLive ? $draft->data->withLive(true) : $draft->data;

        if (Validate::errors($document) !== []) {
            // The review card re-runs the validation on the way in and lists
            // them; passing them through the URL would put sentences in a query
            // string for no gain.
            return $this->toReview($app, []);
        }

        $diff = Diff::of($live->data, $document);

        if ($live->version > 0 && $diff->count === 0) {
            return $this->toReview($app, ['nochange' => true]);
        }

        $expected = $app->request->postInt('expectedBase', -1);
        $holdOn   = Live::hold()->on;
        $lift     = $resume && $holdOn;

        try {
            $published = Repo::publishVersion(new PublishArgs(
                data: $document,
                by: $admin->email,
                source: 'admin',
                summary: $live->version > 0 ? Diff::summarise($diff) : 'First publish',
                changeCount: $diff->count,
                expectedBase: $expected >= 0 ? $expected : null,
                liftHold: $lift,
                expectedRevision: $revision,
            ));
        } catch (PublishConflict $e) {
            Log::warn('publish_conflict', ['live' => $e->live, 'expected' => $expected]);

            return $this->toReview($app, ['conflict' => $e->live]);
        } catch (DraftConflict $e) {
            // Before the generic catch below: DraftConflict is a
            // RuntimeException, and "could not be published, try again" is
            // the wrong sentence for "the draft changed - read it again".
            Log::warn('publish_draft_moved', ['by' => $admin->email, 'current' => $e->current->revision]);

            return $this->toReview($app, ['err' => 'reviewmoved']);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            // The schema refused the document, or another publish holds the
            // lock. Nothing was written either way, and the reason is in the log
            // rather than on the screen.
            Log::exception('publish_failed', $e, ['version' => $live->version + 1]);

            return $this->toEditor($app, ['err' => 'publish']);
        }

        Audit::log($admin->email, 'publish', [
            'version'       => $published->version,
            'summary'       => $live->version > 0 ? Diff::summarise($diff) : 'First publish',
            'resumedPrices' => $lift,
            'goLive'        => $goLive,
            'changes'       => array_map(
                static fn ($line): array => $line->toArray(),
                array_slice($diff->lines, 0, 200),
            ),
        ], $app->request);

        if ($lift) {
            Audit::log($admin->email, 'hold_off', ['version' => $published->version, 'viaPublish' => true], $app->request);
        }

        // Same anchor as the review card came from: the confirmation
        // ("Version 84 is live. Customers see these prices now.") renders in
        // that same `#reviewbox` at the foot of the page, so without it the
        // owner published and was shown nothing.
        return $this->toEditor($app, ['published' => $published->version, 'resumed' => $lift], '', null, 'reviewbox');
    }

    /* ------------------------------------------------------------- internals */

    /**
     * The shape every table action shares: read the posted draft, change one
     * thing, save, redirect.
     *
     * The callback is handed the patched document and returns the document it
     * wants stored plus the codes for the redirect. `detail` inside those codes
     * is for the audit line and never reaches the URL.
     *
     * @param callable(array<string,mixed>): array{document:array<string,mixed>,codes:array<string,mixed>} $change
     */
    private function mutate(App $app, AdminUser $admin, string $action, callable $change): Response
    {
        $draft = Repo::getDraft();
        $form  = DraftForm::of($draft->data, $app->request);

        // Every table action submits the whole editor too, so every one of them
        // can be handed half a document. Same answer as `save()`: write nothing.
        if ($form->truncated) {
            Log::warn('draft_truncated', ['by' => $admin->email, 'action' => $action, 'limit' => (int) ini_get('max_input_vars')]);

            return $this->toEditor($app, ['err' => 'toobig'], '', $form->base);
        }

        $result   = $change($form->document);
        $document = is_array($result['document'] ?? null) ? $result['document'] : $form->document;
        $codes    = is_array($result['codes'] ?? null) ? $result['codes'] : [];

        $detail = is_array($codes['detail'] ?? null) ? $codes['detail'] : [];
        unset($codes['detail']);

        $open = is_string($codes['open'] ?? null) ? (string) $codes['open'] : '';
        unset($codes['open']);

        // A refusal changes nothing at all: the document is not written, so a
        // stale form cannot half-apply.
        if (isset($codes['err'])) {
            return $this->toEditor($app, $codes, $open, $form->base);
        }

        try {
            $this->store(
                $app,
                $admin,
                Migrate::migrate($document),
                $action,
                $detail + ['kept' => $form->kept],
                null,
                $form->revision,
                $document,
            );
        } catch (ValueTooLong) {
            return $this->toEditor($app, ['err' => 'toolong'], '', $form->base);
        } catch (BadDocument) {
            return $this->toEditor($app, ['err' => 'badshape'], $open, $form->base);
        } catch (DraftConflict $e) {
            return $this->refuseStaleDraft($app, $admin, $action, $e, $form->base);
        }

        if ($form->kept > 0) {
            $codes['kept'] = $form->kept;
        }

        if ($form->unnamed > 0) {
            $codes['noname'] = true;
        }

        return $this->toEditor($app, $codes, $open, $form->base);
    }

    /**
     * One answer for "the draft moved under this editor", wherever it is caught.
     *
     * Nothing was written - `Repo::saveDraft()` raises this inside the
     * transaction that would have done the writing - so this is a redirect back
     * to the editor with the sentence and no change of any kind. The base is
     * carried on, because the owner is about to be shown the stored draft and
     * the version he opened the editor at has not moved just because somebody
     * else's save did.
     */
    private function refuseStaleDraft(App $app, AdminUser $admin, string $action, DraftConflict $e, ?int $base): Response
    {
        Log::warn('draft_conflict', [
            'by'      => $admin->email,
            'action'  => $action,
            'current' => $e->current->revision,
        ]);

        return $this->toEditor($app, ['err' => 'draftmoved'], '', $base);
    }

    /**
     * Write the draft and record it.
     *
     * `Caps::assert()` before the write, not after the exception: `draft.updated_by`
     * is VARCHAR(190) and the connection runs in STRICT_TRANS_TABLES, so a long
     * address would be a PDOException and a 500 page rather than a message.
     *
     * THE AUDIT LINE IS WRITTEN ONLY WHEN THE DOCUMENT ACTUALLY CHANGED. The
     * autosave posts every 800ms while somebody types, and a row per keystroke
     * group would bury the forty lines the Security page shows - which is the
     * whole point of keeping them. The draft row itself always records who saved
     * it last and when, so nothing is lost.
     *
     * IT HANDS BACK THE DRAFT FINGERPRINT THE SCREEN WAS DRAWN WITH. There is
     * one draft row for the whole office, and until this argument existed the
     * second of two admins to press Save simply wrote over the first one's
     * prices with no word on either screen. `Repo::saveDraft()` refuses when the
     * stored row no longer has that fingerprint (`Domain\SiteDoc\Draft`), and
     * `DraftForm::revision()` explains why a form that carries no such field at
     * all gets null - the old behaviour - while one whose field arrived empty
     * gets "", which matches nothing and fails closed.
     *
     * THE RAW DOCUMENT IS CHECKED HERE, WHERE IT STILL EXISTS. `$document` is
     * a `SiteData`, and `SiteData::fromArray()` coerces whatever it was built
     * from into a schema-valid shape - so checking it is checking the answer
     * rather than the question. `Repo::publishVersion()` takes a raw array for
     * exactly this reason and the admin never had one to give it: it publishes
     * what is already stored. The one refusal that did survive normalisation,
     * a price past the money cap, therefore arrived three steps later as
     * "That could not be published", about a box the owner had stopped looking
     * at. `$raw` is the array the form built, and a document that cannot be
     * published is refused before it is stored.
     *
     * @param  array<string,mixed>      $detail
     * @param  SiteData|null            $before   what was stored before, when the caller
     *                                            has it: the audit line is skipped when
     *                                            the save changed nothing
     * @param  string|null              $revision the fingerprint the editor was rendered
     *                                            with, or null for a write with no screen
     *                                            behind it
     * @param  array<array-key,mixed>|null $raw   the document as the form built it, before
     *                                            `Migrate`/`SiteData` normalised it
     * IT HANDS BACK THE DRAFT AS THIS WRITE LEFT IT. `Repo::saveDraft()`
     * returns the row it just wrote, fingerprint and all, and the autosave's
     * answer is built from THAT rather than from a second read of the table.
     * The second read is a different question with an almost always identical
     * answer: between the write and it, somebody else's save can land, and
     * then the page would be handed a fingerprint it did not make and would
     * adopt it - so its next autosave would be accepted and would write over
     * that colleague's prices. Silently, automatically, by the very field that
     * exists to stop it.
     *
     * @throws DraftConflict            when somebody else wrote the draft meanwhile;
     *                                  nothing at all has been written
     * @throws BadDocument              when the submitted document is not a shape the
     *                                  site can store; nothing has been written
     */
    private function store(
        App $app,
        AdminUser $admin,
        SiteData $document,
        string $action,
        array $detail = [],
        ?SiteData $before = null,
        ?string $revision = null,
        ?array $raw = null,
    ): Draft {
        $issue = $raw === null ? null : Schema::firstIssue($raw);

        if ($issue !== null) {
            // The path goes to the log and not to the screen: it names a field
            // and a person's typing is next to it, and `Notice` carries codes
            // rather than text on purpose.
            Log::warn('draft_bad_shape', ['by' => $admin->email, 'action' => $action, 'path' => $issue->path]);

            throw new BadDocument($issue);
        }

        Caps::assert('draft', ['updated_by' => $admin->email]);

        $changed = $before === null || Db::json($before->toArray()) !== Db::json($document->toArray());

        $saved = Repo::saveDraft($document, $admin->email, $revision);

        if ($changed) {
            Audit::log($admin->email, $action, $detail, $app->request);
        }

        return $saved;
    }

    /**
     * The answer the autosave expects, or a redirect for a plain submit.
     *
     * The base goes on the redirect and NOT in the JSON: the no-JS submit loses
     * everything that is not in the `Location`, while the page admin.js is
     * running in never navigates and still has the value in its own form.
     *
     * `$extra` is for the fetch path alone - a refusal the browser can act on
     * rather than only print. The redirect path has no use for it: it is about
     * to re-render the whole editor from the database, which knows all of it.
     *
     * A SAVE THAT LANDED IS NOT ALWAYS A SAVE THAT STORED EVERYTHING, and this
     * is where the autosave is told so. `kept` counts the price boxes that
     * arrived holding something that was not a number: the parser keeps what
     * was already stored rather than writing NaN over it, and the redirect
     * path carries the count in `?kept=N` and prints a sentence about it. The
     * fetch path carried nothing, so the owner with JavaScript on - which is
     * every owner, every day - was answered `{"ok":true}` and shown "Draft
     * saved" over prices that had not saved. A price that silently did not
     * save is the worst outcome this screen has.
     *
     * @param array<string,int|string|bool> $codes
     * @param int|null                      $base  the version the editor was opened at; see `toEditor()`
     * @param array<string,mixed>           $extra merged into the JSON refusal
     * @param Draft|null                    $saved the draft as the write that led here left it;
     *                                             see `store()` for why it is not re-read
     */
    private function answer(
        App $app,
        array $codes,
        string $message,
        ?int $base = null,
        array $extra = [],
        ?Draft $saved = null,
    ): Response {
        if (strtolower($app->request->header('x-requested-with')) !== 'fetch') {
            return $this->toEditor($app, $codes, '', $base);
        }

        if ($message !== '') {
            return Response::json(['ok' => false, 'message' => $message] + $extra)->noStore();
        }

        $draft = $saved ?? Repo::getDraft();
        $live  = Repo::getLatestVersion();
        $kept  = is_int($codes['kept'] ?? null) ? (int) $codes['kept'] : 0;

        return Response::json([
            'ok'      => true,
            'savedAt' => Format::fmtDateTime($draft->updatedAt),
            'changes' => $live === null ? 0 : Diff::of($live->data, $draft->data)->count,
            // THE AUTOSAVE MUST BE TOLD WHAT IT JUST MADE. The fingerprint
            // changes with every write, so a page that kept posting the one it
            // was rendered with would have its SECOND autosave refused as stale
            // - by its own first one. admin.js writes this back into the hidden
            // field; see docs/assets.md.
            'revision' => $draft->revision,
            // How many price boxes were left exactly as they were, and the
            // same words the redirect path prints. 0 and "" on a clean save,
            // which is how admin.js knows to take the warning back down.
            'kept'    => $kept,
            'message' => Notice::keptText($kept),
            // A country saved with an empty name box. Its own field and its
            // own sentence, NOT part of `kept`: counted there it read as "a
            // box did not hold a number" over prices that had all saved.
            'noname'  => ($codes['noname'] ?? false) === true,
            'nonameMessage' => ($codes['noname'] ?? false) === true ? Notice::nonameText() : '',
        ])->noStore();
    }

    /**
     * @param array<string,int|string|bool> $codes
     * @param int|null                      $base   the version the editor was opened at, carried
     *                                              across the redirect so the next render can put it
     *                                              back in the form; null when this post had none
     * @param string                        $anchor a fragment to land on when no fold is being
     *                                              reopened - see `toReview()` for why it is needed
     */
    private function toEditor(App $app, array $codes, string $open = '', ?int $base = null, string $anchor = ''): Response
    {
        $path = Notice::url(Routes::RATES, self::withBase($codes, $base));

        // The fold the owner was working in stays open, and the anchor puts it
        // back on screen - the same URL the no-JS "Prices" link uses.
        if ($open !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $open) === 1) {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'open=' . rawurlencode($open) . '#fold-' . rawurlencode($open);
        } elseif ($anchor !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $anchor) === 1) {
            $path .= '#' . $anchor;
        }

        return Response::redirect($app->request->basePath . $path, 303)->noStore();
    }

    /**
     * THE REVIEW CARD IS THE LAST THING ON THE PAGE, so the redirect names it.
     *
     * `/admin/rates/review` re-renders the WHOLE six-section editor and puts
     * the card in `#reviewbox` at the very bottom - about eleven screenfuls
     * down at 1366x768 on the live document. Without the fragment the page
     * loaded at the top, nothing above the fold changed, and the only thing
     * that landed the card was admin.js scrolling eight thousand pixels with a
     * smooth animation that any scroll input cancels and that does nothing at
     * all with the script off. So the owner pressed "Review and publish",
     * apparently nothing happened, and the diff he needed to read - "1 kg: PKR
     * 7,674 -> PKR 9,999 +30%, please double-check" - was never seen.
     *
     * The fragment does it in the browser, before any script runs; admin.js's
     * landing stays as the refinement it always should have been, and
     * admin.css gives `#reviewbox` the scroll-margin that clears the sticky
     * bar over it.
     *
     * @param array<string,int|string|bool> $codes
     * @param int|null                      $base as `toEditor()`
     */
    private function toReview(App $app, array $codes, ?int $base = null): Response
    {
        return Response::redirect(
            $app->request->basePath . Notice::url(Routes::REVIEW, self::withBase($codes, $base)) . '#reviewbox',
            303,
        )->noStore();
    }

    /**
     * Put the opened-at base on the redirect, when the post carried one.
     *
     * THIS IS THE LINK THAT MAKES THE STALE-PUBLISH GUARD REACHABLE. Every
     * action here is post/redirect/get, so the version the editor was opened at
     * has to survive a round trip through a `Location` header or the next render
     * has nothing to print but the CURRENT live version - which always agrees
     * with itself, which is why the guard has never once fired. `Notice::url()`
     * only lets through a whole number under a name it knows, so this cannot
     * become a way to put anything else in a URL.
     *
     * @param  array<string,int|string|bool> $codes
     * @return array<string,int|string|bool>
     */
    private static function withBase(array $codes, ?int $base): array
    {
        return $base === null ? $codes : $codes + ['base' => $base];
    }

    /** One price change, rounded the way the form asked. */
    private static function adjust(float $price, string $mode, float $value, float $round): float
    {
        $next = $mode === 'pct' ? $price * (1 + $value / 100) : $price + $value;

        return max(0.0, Money::roundTo($next, $round));
    }

    /** A posted number, or null when the box was blank or held something else. */
    private static function number(mixed $raw): ?float
    {
        if (!is_scalar($raw)) {
            return null;
        }

        $text = trim((string) $raw);

        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        $number = (float) $text;

        return is_finite($number) ? $number : null;
    }
}
