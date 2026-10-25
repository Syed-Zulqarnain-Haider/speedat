<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Core\App;
use Speedat\Core\Request;
use Speedat\Http\Admin\Notice;
use Speedat\View\Admin\HoldPanel;
use Speedat\View\Admin\RollbackAsk;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;

/**
 * The rates editor, screen by screen: the six sections, the per-kilogram
 * expander, the publish bar, the review card, the hold, and what an editor
 * who may not publish is shown instead of the buttons.
 */
final class RatesEditorTest extends AdminViewTestCase
{
    /** The fingerprint of the draft row the fixture screen was drawn from. */
    private const REVISION = '1a2b3c4d5e6f7a8b';

    /**
     * @param int $baseVersion the published version this editor was OPENED at.
     *                         11 is the fixture's live version, so the default
     *                         is the ordinary case; a test that wants a stale
     *                         editor passes a lower one.
     */
    private function render(
        array $overrides = [],
        bool $owner = true,
        int $newLeads = 3,
        int $baseVersion = 11,
        string $revision = self::REVISION,
    ): string {
        return $this->view()->render('admin/rates', [
            'page'          => $this->ratesPage($overrides),
            'shell'         => $this->shell('rates', $owner),
            'newLeads'      => $newLeads,
            'baseVersion'   => $baseVersion,
            'draftRevision' => $revision,
        ]);
    }

    /**
     * The roll-back question for version 10, built the way the server builds
     * it - the sentence comes from `Notice`, so a test cannot pass while the
     * words the owner reads say something else.
     *
     * @param array<string,mixed> $overrides constructor arguments by name
     */
    private function rollbackAsk(array $overrides = []): RollbackAsk
    {
        $version = isset($overrides['version']) ? (int) $overrides['version'] : 10;
        $app     = new App(Request::synthetic('GET', Routes::RATES, [], ['rollback' => (string) $version]));

        return new RollbackAsk(...array_replace([
            'version'  => $version,
            'question' => Notice::rollbackQuestion($app),
            'problems' => [],
            'holdOn'   => false,
            'unknown'  => false,
        ], $overrides));
    }

    /**
     * How many variables each form on the page would post, by form id.
     *
     * THIS IS THE MEASUREMENT THE EDITOR LIVES UNDER. PHP stops reading a
     * request body at `max_input_vars` - 1000 on this host - and says nothing
     * about it, so a form that grows past that line saves its first thousand
     * fields and drops the rest. Counting them out of the rendered markup is
     * the only check that fails BEFORE a customer is quoted last week's price.
     *
     * A control reaches a form either by being inside it or by carrying
     * `form="<id>"`, and both are counted, once each. Submit buttons are
     * counted too, which over-counts by a few - only the one pressed is
     * posted - and over-counting is the safe direction for a ceiling.
     *
     * @return array<string,int>
     */
    private function fieldsByForm(string $html): array
    {
        $counts = [];

        preg_match_all('/<(?:input|select|textarea|button)\b[^>]*>/i', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/\bname="/', $tag) !== 1) {
                continue;
            }
            if (preg_match('/\bform="([^"]+)"/', $tag, $m) === 1) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }

        preg_match_all('/<form\b[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);

        foreach ($forms as $form) {
            $counts[$form[1]] = $counts[$form[1]] ?? 0;

            preg_match_all('/<(?:input|select|textarea|button)\b[^>]*>/i', $form[2], $inner);

            foreach ($inner[0] as $tag) {
                // A control that names its own form was counted above;
                // counting it again would double every settings field.
                if (preg_match('/\bname="/', $tag) === 1 && preg_match('/\bform="/', $tag) !== 1) {
                    $counts[$form[1]]++;
                }
            }
        }

        return $counts;
    }

    /* ---------------- one form per country ---------------- */

    /**
     * NO FORM ON THIS PAGE MAY COME NEAR `max_input_vars`.
     *
     * This is the regression test for the day the editor could not save at
     * all. The rates table was one form carrying the whole document; eleven
     * countries priced per kilogram for three services put 1009 variables on
     * the wire against a limit of 1000, PHP stopped reading at a thousand
     * without saying so, and the guard in `Http\Admin\DraftForm` refused the
     * save - correctly, and with nothing the owner could do about it.
     *
     * The fixture here is deliberately small (4 countries, 2 services, 25 kg),
     * so the number to watch is not the total: it is that the total does not
     * GROW WITH THE DOCUMENT. A country posts its own row, so the largest form
     * on the page is one country however many countries there are.
     */
    public function testNoSingleFormPostsAnythingLikeMaxInputVars(): void
    {
        $counts = $this->fieldsByForm($this->render());

        foreach ($counts as $id => $count) {
            $this->assertTrue(
                $count < 500,
                'form#' . $id . ' posts ' . $count . ' variables; max_input_vars is 1000 on this host and cannot be raised from PHP',
            );
        }

        // One form per country, and each carries that country alone: name,
        // "on site", position, and per service days + two fill boxes + 25
        // kilogram boxes - plus the token, the fingerprint and the base.
        $this->assertTrue(isset($counts['row-ca']), 'Canada has no form of its own');
        $this->assertTrue(isset($counts['row-tr']), "T\u{fc}rkiye has no form of its own");
        $this->assertTrue(
            $counts['row-ca'] > 50 && $counts['row-ca'] < 120,
            'Canada posts ' . $counts['row-ca'] . ' variables, which is not one country\'s worth',
        );
    }

    /**
     * The table is not one form, and the proof is that a country's boxes name
     * their own form. A <form> cannot be a child of <tbody>, so the row forms
     * are printed above the table and the inputs are tied to them with the
     * `form` attribute - plain HTML, no script involved.
     */
    public function testEveryBoxInARowBelongsToThatCountrysOwnForm(): void
    {
        $html = $this->render();

        $this->assertStringContains('<form id="row-ca" class="rowform" method="post" action="' . Routes::SAVE . '"', $html);
        $this->assertStringContains('name="dest[ca][name]" value="Canada" maxlength="120"' . "\n" . '                   aria-label="Destination name" form="row-ca"', $html);
        $this->assertStringContains('name="dest[ca][rates][exp][grid][1]"', $html);
        $this->assertStringContains('aria-label="Canada Express 1 kg price" form="row-ca"', $html);
        // Canada's boxes are not in Türkiye's form and never the other way.
        $this->assertSame(0, substr_count($html, 'name="dest[tr][name]" value="T&#252;rkiye" maxlength="120"' . "\n" . '                   aria-label="Destination name" form="row-ca"'), 'a row claims another row\'s form');
    }

    /** Each country has its own Save, which is the whole no-JavaScript path. */
    public function testEachCountryHasItsOwnSaveAndSaysSo(): void
    {
        $html = $this->render();

        $this->assertStringContains('aria-label="Save Canada"', $html);
        $this->assertStringContains('>Save this country</button>', $html, 'and one at the foot of the open fold');
        $this->assertStringContains('Each country saves on its own', $html, 'the table says how it saves');
        // admin.js hides these once it is autosaving the same forms.
        $this->assertTrue(substr_count($html, 'data-save-submit') >= 5, 'every explicit Save is marked for admin.js to hide');
    }

    /**
     * EVERY FORM THAT WRITES CARRIES THE THREE KEYS: the token, the draft
     * fingerprint it was drawn from, and the version the editor was opened
     * at. A row form missing the fingerprint would be the one that silently
     * overwrites a colleague.
     */
    public function testEveryWritingFormCarriesTheTokenTheFingerprintAndTheBase(): void
    {
        $html = $this->render();

        foreach (['row-ca', 'row-tr', 'row-de', 'row-zz', 'tableform', 'editor'] as $id) {
            $this->assertTrue(
                preg_match('/<form\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/form>/is', $html, $m) === 1,
                'form#' . $id . ' is not on the page',
            );

            $this->assertStringContains('name="_token"', $m[1], 'form#' . $id . ' has no CSRF token');
            $this->assertStringContains('name="revision" value="' . self::REVISION . '"', $m[1], 'form#' . $id . ' has no draft fingerprint');
            $this->assertStringContains('name="expectedBase" value="11"', $m[1], 'form#' . $id . ' has no opened-at version');
        }
    }

    /** Add country and Sort A-Z belong to the table, not to a country. */
    public function testTheTableButtonsPostTheirOwnSmallForm(): void
    {
        $html = $this->render();

        $this->assertStringContains('form="tableform" formaction="' . Routes::SORT_DESTINATIONS . '"', $html);
        $this->assertStringContains('form="tableform" formaction="' . Routes::ADD_DESTINATION . '"', $html);

        $counts = $this->fieldsByForm($html);
        $this->assertTrue(($counts['tableform'] ?? 0) <= 6, 'the table form should carry the keys and a button, nothing else');
    }

    /* ---------------- whose draft is this ---------------- */

    /**
     * ONE DRAFT ROW, SEVERAL ADMINS. The editor says whose work is on the
     * screen before anybody types over it, in the domain's own words.
     */
    public function testTheEditorSaysWhoLastEditedTheDraft(): void
    {
        $html = $this->render(['draftEditedBy' => 'edited by office@speedat.test at 23 Sept 2026, 14:05']);

        $this->assertStringContains('This draft was edited by office@speedat.test at 23 Sept 2026, 14:05.', $html);
    }

    /**
     * A REFUSED SAVE NAMES WHO HAS IT AND OFFERS A WAY TO LOOK. The box is
     * the same one admin.js fills after a refused autosave, so the two paths
     * cannot say different things in different places.
     */
    public function testARefusedSaveShowsWhoHasTheDraftAndOffersAReload(): void
    {
        $html = $this->render([
            'draftMoved'    => true,
            'draftEditedBy' => 'edited by office@speedat.test at 23 Sept 2026, 14:05',
        ]);

        $this->assertStringContains('Nothing was saved.', $html);
        $this->assertStringContains('it was edited by office@speedat.test', $html);
        $this->assertStringContains('data-conflict-reload>Reload</a>', $html);

        // And it is not on the page at all when nothing has been refused.
        $this->assertStringContains('data-conflict-box hidden', $this->render(), 'the conflict box shows itself unprompted');
    }

    /**
     * TWO VERSIONS, TWO JOBS (fix of 2026-10-25, app/Views/admin/partials/review.php).
     *
     * The editor's own forms stay pinned to the version the editor was OPENED
     * at, so saving on top of a colleague's newer publish is still caught.
     * The review card's Publish form carries the live version ITS CHANGE LIST
     * WAS COMPARED WITH (nextVersion - 1): pinning it to the opened-at version
     * refused every publish once the stored draft fell behind live, with no
     * way out on screen. A publish landing between drawing the card and
     * pressing the button still disagrees with that number and is refused.
     */
    public function testThePublishCarriesTheComparedWithVersionAndTheEditorTheOpenedAtOne(): void
    {
        // The editor was opened at version 10; version 11 has gone live since.
        $html = $this->render(['review' => $this->review()], true, 3, 10);

        $card = strstr($html, '<div class="card review">');
        $this->assertTrue(is_string($card), 'the review card is on the page');
        $card = (string) strstr((string) $card, '</form>', true);
        $rest = str_replace($card, '', $html);

        $this->assertStringContains('name="expectedBase" value="11"', $card, 'the publish is pinned to the version the card was compared with');
        $this->assertStringNotContains('name="expectedBase" value="10"', $card, 'the card re-used the stale opened-at version');
        $this->assertStringContains('name="expectedBase" value="10"', $rest, 'the editor forms stay pinned to the opened-at version');
        $this->assertStringNotContains('name="expectedBase" value="11"', $rest, 'the editor forms re-read the live version');
        $this->assertStringContains('<h2>Publishing version 12</h2>', $html, 'and the card still describes the next version');
        $this->assertStringContains('This draft was started from version 10, and version 11 has gone live since.', $card, 'and says the draft is behind');
    }

    public function testADraftOpenedAtTheLiveVersionGetsNoBehindNotice(): void
    {
        $html = $this->render(['review' => $this->review()], true, 3, 11);

        $this->assertStringNotContains('This draft was started from version', $html);
    }

    /* ---------------- the sections ---------------- */

    /**
     * EIGHT sections, numbered 01 to 08, in order - Popup and then Reviews
     * (owner, 2026-10-05) joined before History - and the "Import from Excel"
     * section the React editor had is not there under any name or number.
     * The eyebrow separator is a plain hyphen since 2026-10-11.
     */
    public function testThereAreEightSectionsNumberedInOrder(): void
    {
        $html = $this->render();

        $this->assertSame(8, count(Sections::ALL), 'the section list itself');

        $expected = [
            ['01', 'Rates', 'sec-rates'],
            ['02', 'Bulk adjust', 'sec-bulk'],
            ['03', 'Test', 'sec-test'],
            ['04', 'Settings', 'sec-settings'],
            ['05', 'Pages', 'sec-content'],
            ['06', 'Popup', 'sec-promo'],
            ['07', 'Reviews', 'sec-reviews'],
            ['08', 'History', 'sec-history'],
        ];

        $last = 0;
        foreach ($expected as [$no, $eyebrow, $anchor]) {
            $at = strpos($html, 'id="' . $anchor . '"');
            $this->assertTrue($at !== false, $anchor . ' is missing');
            $this->assertTrue($at > $last, $anchor . ' is out of order');
            $last = (int) $at;
            $this->assertStringContains('<p class="eyebrow">' . $no . ' - ' . $eyebrow, $html, 'the eyebrow of ' . $anchor);
        }

        // Nothing numbered 09, and no Excel import under any name.
        $this->assertStringNotContains('<p class="eyebrow">09 ', $html, 'there is no ninth section');
        $this->assertStringNotContains('sec-import', $html);
        $this->assertStringNotContains('Import from Excel', $html);
    }

    /** The section nav, the sidebar sub-list and the anchors cannot drift apart. */
    public function testTheSectionNavAndTheSidebarPointAtTheSameAnchors(): void
    {
        $html = $this->render();

        foreach (Sections::all() as $section) {
            $this->assertStringContains('href="#' . $section['anchor'] . '"', $html, $section['id'] . ': no link to it');
            $this->assertStringContains('id="' . $section['anchor'] . '"', $html, $section['id'] . ': no anchor');
        }

        // Two lists point at them: the sticky pills and the sidebar sub-list.
        $this->assertStringContains('class="subnav sections"', $html);
        $this->assertStringContains('class="anav-sub"', $html);
        $this->assertSame(2, substr_count($html, 'href="#sec-history"'), 'both lists list every section');
    }

    /* ---------------- the table ---------------- */

    /**
     * THERE IS NO DOCS COLUMN. The owner removed the flat document rate from
     * the product on 2026-09-27; everything is priced by weight. What is left
     * per service is Days and the per-kilogram ladder, and the word
     * "document" must not survive anywhere the owner can read it.
     */
    public function testTheTableHasNoDocumentsColumnAnywhere(): void
    {
        $html = $this->render();
        $page = $this->ratesPage();

        $this->assertStringContains('<th class="grp exp" colspan="1">Express</th>', $html, 'one column per service in grid mode');
        $this->assertStringContains('<th class="grp eco" colspan="1">Economy</th>', $html);
        $this->assertSame(2, substr_count($html, '<th>Days</th>'), 'one Days heading per service');
        $this->assertStringContains(
            '<th class="grid-cell">' . $page->gridHeadingTop() . ' <br>' . $page->gridHeadingBottom() . '</th>',
            $html,
            'the Prices heading, on two lines so it clears the pinned actions column'
        );

        // Not a heading, not a box, not a hint, not a bulk-adjust tick.
        $this->assertSame(0, preg_match('/(?<![a-z])doc(ument)?s?(?![a-z])/i', $html), 'the editor still says "document"');
        $this->assertStringNotContains('[doc]', $html, 'a document price field is still posted');
        $this->assertStringNotContains('docMaxKg', $html, 'the document weight limit is still a setting');
    }

    /**
     * Every column the table draws is counted, so the fold's colspan lines up
     * under it. A fold that spans the wrong number of columns is how the
     * per-kilogram editor ends up narrower than the row above it - and the
     * count changed when Docs left, which is exactly when it goes wrong.
     */
    public function testTheFoldSpansExactlyAsManyColumnsAsTheTableHas(): void
    {
        $html = $this->render();
        $page = $this->ratesPage();

        // name + on site + (Days) x 2 services + Prices + actions
        $this->assertSame(6, $page->columnCount(), 'grid mode with two services');
        $this->assertSame(4, substr_count($html, '<td colspan="6">'), 'one fold per country, each spanning the table');

        preg_match('#<thead>(.*?)</thead>#s', $html, $head);
        $rows = explode('<tr>', $head[1]);
        $this->assertSame(
            $page->columnCount(),
            substr_count((string) end($rows), '<th'),
            'the heading row has as many cells as columnCount() claims'
        );
    }

    /**
     * The expander: 25 boxes per service, named for the kilogram they hold,
     * and a link to `?open=` so it folds open without JavaScript.
     */
    public function testThePricesExpanderHoldsOneBoxPerKilogramAndOpensWithoutJavaScript(): void
    {
        $html = $this->render();

        $this->assertStringContains('href="' . Routes::openFold('ca') . '"', $html, 'the no-JS expander link');
        $this->assertStringContains('data-fold-toggle="fold-ca" aria-expanded="false"', $html, 'and the hook admin.js folds in place with');
        $this->assertStringContains('name="dest[ca][rates][exp][grid][1]"', $html, '1 kg');
        $this->assertStringContains('name="dest[ca][rates][exp][grid][25]"', $html, '25 kg');
        $this->assertStringContains('aria-label="Canada Express 25 kg price"', $html, 'every box is named for a screen reader');

        // 25 kilograms x 2 services x 4 destinations.
        $this->assertSame(200, substr_count($html, 'data-kg-box'), 'one box per kilogram per service per country');

        // Türkiye's row was rendered open, Canada's folded away.
        $this->assertStringContains('id="fold-tr" data-open="1"', $html, 'the open row');
        $this->assertStringContains('id="fold-ca" data-open="0"', $html, 'a folded row');
        $this->assertStringContains('id="fold-ca" data-open="0" hidden', $html, 'a folded row is hidden');
    }

    /**
     * Fill from a base price: a 1 kg price, a per-kilogram step, and the two
     * fill buttons - both real submits, so the tool works with the script off.
     *
     * The two boxes now carry LABELS instead of placeholders. The placeholder
     * they used to carry was clipped by the browser to "1 kg pri" in its
     * 110px box, and vanished entirely the moment anything was typed into it.
     */
    public function testFillFromABasePriceIsLabelledAndIsARealSubmit(): void
    {
        $html = $this->render();

        $this->assertStringContains('data-fill-scope', $html);
        $this->assertStringContains('name="dest[ca][rates][exp][fill][start]" data-fill-start', $html);
        $this->assertStringContains('name="dest[ca][rates][exp][fill][step]" data-fill-step', $html);
        $this->assertStringContains('<span>1 kg price</span>', $html, 'a label, not a placeholder');
        $this->assertStringContains('<span>+ each extra kg</span>', $html);
        $this->assertStringNotContains('placeholder="1 kg price"', $html, 'the clipped placeholder is gone');
        $this->assertStringContains('value="blanks:ca:exp" data-fill="blanks"', $html, 'fill blanks');
        $this->assertStringContains('value="all:ca:exp" data-fill="all"', $html, 'fill all');
        // Both are submits of the editor form, so they work without JavaScript.
        $this->assertSame(16, substr_count($html, 'formaction="' . Routes::FILL . '"'), 'two buttons per service per country');
        // ...and the sum is printed before it is applied, by admin.js.
        $this->assertSame(8, substr_count($html, 'data-fill-preview'), 'one preview line per ladder');
    }

    /* ---------------- the ladder ---------------- */

    /**
     * WHAT IS WRONG WITH A LADDER IS MARKED ON THE SERVER, not only by
     * admin.js, so the owner still sees it with the script off.
     *
     * Türkiye's Express ladder in the fixture has both faults: 3 and 4 kg are
     * blank between priced weights, and 6 kg is priced below 5 kg.
     */
    public function testABackwardsOrMissingRungIsMarkedInPlaceWithoutJavaScript(): void
    {
        $html = $this->render();

        // 6 kg (9,000) sits under 5 kg (10,400): the ladder runs backwards.
        $this->assertStringContains('<label class="grid-box is-low" data-kg="6">', $html, '6 kg is marked backwards');
        // 3 and 4 kg are blank with prices above and below them.
        $this->assertStringContains('<label class="grid-box is-gap" data-kg="3">', $html, '3 kg is marked as a gap');
        $this->assertStringContains('<label class="grid-box is-gap" data-kg="4">', $html, '4 kg is marked as a gap');
        $this->assertStringContains('<label class="grid-box" data-kg="25">', $html, 'a sound rung carries no mark');

        // The sentence, in place, pointing at the weights.
        $this->assertStringContains('6 kg is priced below the kilogram underneath, so a heavier parcel costs less.', $html);
        // A blank box is not a price (owner, 2026-09-28): the note says the
        // weight is not offered, never the old "charged at the next heavier
        // price", which told the owner a customer was quoted when he was not.
        $this->assertStringContains('3 and 4 kg are blank, so they are not offered (customers are sent to WhatsApp).', $html);
        $this->assertStringNotContains('charged at the next heavier price', $html, 'the ladder note no longer promises it');
        $this->assertStringNotContains('uses the next heavier priced one', $html, 'nor does the lede');
        $this->assertStringContains('a blank kilogram is not offered at that weight.', $html, 'the lede says what happens instead');

        // Every flagged box points at that sentence for a screen reader.
        $this->assertStringContains('aria-invalid="true" aria-describedby="ladder-tr-exp"', $html);
    }

    /**
     * THREE TONES, NOT TWO. A rung priced below the one under it is WRONG and
     * is called out; a blank rung is merely sparse and is stated quietly; a
     * sound ladder says nothing at all. Painting twenty ordinary blanks in
     * alarm orange is how an owner learns to look past alarm orange.
     */
    public function testASparseLadderIsStatedQuietlyAndOnlyABackwardsRungIsAWarning(): void
    {
        $html = $this->render();

        // Türkiye Express has a backwards rung: a warning.
        $this->assertStringContains('<p class="ladder-note warn" id="ladder-tr-exp"', $html);
        // Canada Express is merely sparse: the same facts, the quiet tone.
        $this->assertStringContains('<p class="ladder-note info" id="ladder-ca-exp"', $html);
        // Germany Express prices 1 kg and stops: nothing is wrong and nothing
        // is missing under a price, so it says nothing.
        $this->assertStringContains('<p class="ladder-note ok" id="ladder-de-exp" data-ladder-note hidden></p>', $html);
    }

    /** A blank rung with nothing priced above it is "we do not carry that", not a gap. */
    public function testBlanksAboveTheLastPriceAreNotCalledGaps(): void
    {
        $html = $this->render();

        // Economy for Türkiye prices 1 kg and 5 kg and nothing else. 2-4 kg are
        // gaps; 6-25 kg are simply not carried.
        $this->assertStringContains('name="dest[tr][rates][eco][grid][6]" value="" placeholder="-"', $html);
        $this->assertSame(
            1,
            preg_match(
                '/<label class="grid-box" data-kg="6">\s*<span class="kg">6 kg<\/span>\s*<input[^>]*data-kg="6"\s*name="dest\[tr\]\[rates\]\[eco\]\[grid\]\[6\]"/s',
                $html
            ),
            '6 kg Economy is blank but unmarked: nothing heavier is priced'
        );
        // 2 to 4 kg ARE gaps on the same ladder: 5 kg is priced above them.
        $this->assertStringContains('<label class="grid-box is-gap" data-kg="2">', $html);
    }

    /** A box that differs from the live version is marked, so a change is visible rung by rung. */
    public function testAnEditedRungIsMarkedAgainstTheLiveVersion(): void
    {
        $html = $this->render();

        // Canada's Express 1 kg is 5,220 in the draft and 4,800 live.
        $this->assertStringContains('<label class="grid-box is-edited" data-kg="1">', $html);
        $this->assertStringContains('name="dest[ca][rates][exp][grid][1]" value="5220"', $html);
    }

    /** How much of a ladder is priced, said out loud rather than counted by eye. */
    public function testEachLadderSaysHowManyRungsArePriced(): void
    {
        $html = $this->render();

        $this->assertStringContains('<span class="hint" data-ladder-count>8 of 25 priced</span>', $html, 'Canada Express');
        $this->assertStringContains('<span class="hint" data-ladder-count>2 of 25 priced</span>', $html, "T\u{fc}rkiye Economy");
    }

    /**
     * Copying a ladder onto another country, and the one-weight column view,
     * are the two things that CANNOT work without a script: both edit boxes
     * that live elsewhere on the page. They are rendered hidden and turned on
     * by admin.js, exactly as "Copy as CSV" already was.
     */
    public function testTheCopyToolAndTheColumnViewStartHiddenAndCarryNoPostedField(): void
    {
        $html = $this->render();

        $this->assertStringContains('<div class="tool" data-copy-tool hidden>', $html, 'hidden until admin.js shows it');
        $this->assertStringContains('<option value="*">Every other country</option>', $html);
        $this->assertStringContains('aria-label="Copy Canada Express prices to"', $html);
        $this->assertStringContains('data-colview-open aria-expanded="false" aria-controls="colview" hidden>', $html);
        $this->assertStringContains('<section class="colview" id="colview" data-colview hidden', $html);
        $this->assertStringContains('<p class="hint keys-note" data-keys-note hidden>', $html, 'the keyboard line is only true with a script');

        // NOT ONE NEW POSTED FIELD. The editor is one form carrying the whole
        // document and PHP stops reading a body at max_input_vars without
        // telling anyone (Http\Admin\DraftForm::wasTruncated); a control that
        // quietly added a name per country would walk the form into that wall.
        preg_match('#<select[^>]*data-copy-target[^>]*>#', $html, $select);
        $this->assertStringNotContains('name=', $select[0], 'the copy target must never be posted');
        preg_match('#<section class="colview".*?</section>#s', $html, $panel);
        $this->assertStringNotContains('name=', $panel[0], 'nothing in the column view is posted');
    }

    /** The running check has somewhere to print, and prints nothing until asked. */
    public function testTheRunningCustomerPriceHasAPlaceOnEveryLadder(): void
    {
        $html = $this->render();

        $this->assertSame(8, substr_count($html, 'data-ladder-running'), 'one per service per country');
        $this->assertStringContains('<p class="ladder-running" data-ladder-running aria-live="polite"></p>', $html, 'empty without a script');
    }

    /** Add country, Remove, Undo and Sort are submits, not scripts. */
    public function testAddRemoveUndoAndSortAreRealSubmits(): void
    {
        $html = $this->render();

        $this->assertStringContains('formaction="' . Routes::ADD_DESTINATION . '" name="add" value="1"', $html);
        $this->assertStringContains('formaction="' . Routes::SORT_DESTINATIONS . '" name="sort" value="az"', $html);
        $this->assertStringContains('formaction="' . Routes::REMOVE_DESTINATION . '"', $html);
        $this->assertStringContains('data-confirm="Confirm remove"', $html, 'Remove arms before it fires');
        $this->assertStringContains('formaction="' . Routes::UNDO_ROW . '"', $html);
        $this->assertStringContains('name="undo" value="ca" data-undo-row', $html);
        $this->assertStringContains('aria-label="Remove Canada"', $html, 'the buttons name their row');
        $this->assertStringContains('aria-label="Undo changes to Canada"', $html);
    }

    /** Undo is offered on the changed rows only, and carries the live values for the browser. */
    public function testUndoIsOfferedOnlyOnChangedRowsAndCarriesTheLiveValues(): void
    {
        $html = $this->render();

        // Canada and Germany are the changed pair in the fixture.
        $this->assertSame(2, substr_count($html, 'data-undo-row'), 'one Undo per changed row');
        $this->assertStringContains('value="ca"', $html);
        $this->assertStringNotContains('name="undo" value="tr"', $html, 'an unchanged row has no Undo');
        // The live snapshot: Canada's Express 1 kg was 4800 before the draft moved it.
        $this->assertStringContains('data-live=', $html);
        $this->assertStringContains('4800', $html, "the live value Undo restores");
    }

    /** The changed rows are marked, and the marker is the class admin.css paints. */
    public function testChangedRowsAreMarked(): void
    {
        $html = $this->render();

        $this->assertSame(2, substr_count($html, '<tr class=" changed"') + substr_count($html, '<tr class="inactive changed"'), 'two marked rows');
    }

    /**
     * A row the filter hides is still IN THE FORM.
     *
     * This is the bug the hidden attribute avoids: dropping the row from the
     * markup would post a document without it, and searching for "Canada"
     * would delete every other country on the next save.
     */
    public function testAFilteredRowKeepsItsFieldsSoNothingIsLostOnSave(): void
    {
        $html = $this->render(['filter' => 'canada']);

        $this->assertStringContains('value="canada"', $html, 'the search box keeps the word');
        // Germany is the hidden row in the fixture; its name field is still there.
        $this->assertStringContains('name="dest[de][name]"', $html, 'a hidden row still posts');
        $this->assertStringContains('data-name="deutschland"', $html, 'the filter key is accent-free and lower case');
        $this->assertStringContains('data-filter-table="rates-table"', $html);
        // ...and the no-JS path is a GET form of its own, not the editor.
        $this->assertStringContains('<form id="findform" method="get"', $html);
        $this->assertStringContains('name="q" form="findform"', $html);
    }

    /** Both CSV controls are there, one of them a plain link, and neither reads a file. */
    public function testTheCsvIsExportOnly(): void
    {
        $html = $this->render();

        $this->assertStringContains('>Download as CSV</a>', $html);
        $this->assertStringContains('>Copy as CSV</button>', $html);
        $this->assertStringContains('href="' . Routes::EXPORT_CSV . '" download', $html);
        $this->assertStringContains('<pre id="rates-csv" hidden>', $html, 'the copy buffer');
        // No file input anywhere: nothing travels the other way.
        $this->assertStringNotContains('type="file"', $html);
        $this->assertStringNotContains('enctype="multipart/form-data"', $html);
        $this->assertSaysNothingAboutSheets($html, 'the rates editor');
    }

    /** The actions column is the last cell of every row, which is the one admin.css pins. */
    public function testTheRowActionsAreTheLastColumnSoTheyStayOnScreen(): void
    {
        $html = $this->render();

        $this->assertSame(4, substr_count($html, '<td class="ctr row-actions">'), 'one actions cell per row');
        foreach (['ca', 'tr', 'de', 'zz'] as $id) {
            $this->assertStringContains('value="' . $id . '" data-confirm="Confirm remove"', $html, $id . ': Remove is in the pinned column');
        }
        $this->assertStringContains('<div class="tablewrap">', $html, 'the table scrolls inside a wrapper');
    }

    /* ---------------- the publish bar and the review ---------------- */

    public function testThePublishBarStatesWhatIsUnpublishedAndWhatCustomersSee(): void
    {
        $html = $this->render();

        $this->assertStringContains('class="pubbar"', $html);
        $this->assertStringContains('<strong data-change-count>2 unpublished changes</strong>', $html);
        $this->assertStringContains('Customers still see version 11.', $html);
        $this->assertStringContains('<span data-save-state>Draft saved', $html);
        $this->assertStringContains('formaction="' . Routes::REVIEW . '"', $html, 'Review saves the draft it reviews');
        $this->assertStringContains('form="discardform"', $html);
        // Without JavaScript the bar still has a Save.
        $this->assertStringContains('<noscript>', $html);
    }

    /** Nothing to publish, nothing to discard. */
    public function testWithNoChangesBothPublishBarButtonsAreDisabled(): void
    {
        $html = $this->render(['changeCount' => 0]);

        $this->assertStringContains('No unpublished changes', $html);
        $this->assertSame(
            1,
            preg_match('#form="discardform" data-discard\s+data-confirm="[^"]*" disabled>Discard all changes</button>#', $html),
            'Discard is refused'
        );
        $this->assertStringContains('formaction="' . Routes::REVIEW . '" disabled>', $html, 'and so is Review and publish');
    }

    /** The diff reads as changes: the old struck through, the new bold, the big moves flagged. */
    public function testTheReviewCardShowsTheDiffWithOldAndNewAndFlags(): void
    {
        $html = $this->render(['review' => $this->review()]);

        $this->assertStringContains('<h2>Publishing version 12</h2>', $html);
        $this->assertStringContains('3 changes.', $html);
        $this->assertStringContains('<span class="old">4,800</span>', $html);
        $this->assertStringContains('<span class="new">5,220</span>', $html);
        $this->assertStringContains('<span class="flag"> +9%</span>', $html);
        $this->assertStringContains('Customers see the new prices as soon as it is published.', $html);
        // A warning never blocks; it sits above the button.
        $this->assertStringContains('Worth a second look (publishing is still allowed)', $html);
        $this->assertStringContains('is 9% above the live price', $html);
        $this->assertStringContains('name="expectedBase" value="11"', $html, 'the publish is pinned to the reviewed version');
    }

    /** The two ticks: "Rates are live" while the site shows samples, "show prices again" while held. */
    public function testTheReviewCardOffersTheGoLiveAndResumeTicksOnlyWhenTheyApply(): void
    {
        $plain = $this->render(['review' => $this->review()]);
        $this->assertStringContains('Rates are live - remove the sample notice from the website', $plain);
        $this->assertStringNotContains('Show prices again after publishing', $plain, 'nothing is on hold');

        $held = $this->render([
            'hold'   => new HoldPanel(on: true, message: 'New rates on Monday.', isOwner: true),
            'review' => $this->review(['holdOn' => true]),
        ]);
        $this->assertStringContains('Show prices again after publishing (lifts the hold)', $held);
        $this->assertStringContains('Prices are on hold; choose below whether this publish shows them again.', $held);

        // Each tick has its hidden companion, or unticking it would post nothing.
        $this->assertStringContains('<input type="hidden" name="goLive" value="0">', $plain);
        $this->assertStringContains('<input type="hidden" name="resume" value="0">', $held);
    }

    /** An error replaces the card: a document that cannot price a parcel has no Publish button. */
    public function testErrorsReplaceTheReviewCardEntirely(): void
    {
        $html = $this->render(['review' => $this->review(['errors' => ['Volumetric divisor must be between 1000 and 10000']])]);

        $this->assertStringContains('Fix these before publishing', $html);
        $this->assertStringContains('Volumetric divisor must be between 1000 and 10000', $html);
        $this->assertStringNotContains('Publishing version 12', $html, 'the card is gone');
        $this->assertStringNotContains('action="' . Routes::PUBLISH . '"', $html, 'and so is the publish form');
    }

    /* ---------------- who may publish ---------------- */

    /**
     * An editor sees no Publish and no Resume - and is told who does, rather
     * than being shown a button that will be refused.
     *
     * Hiding a control is never the control; the server re-checks the role.
     * This test is about the screen not lying.
     */
    public function testAnEditorSeesNoPublishAndNoResumeControls(): void
    {
        $html = $this->render([
            'canPublish' => false,
            'review'     => $this->review(),
            'hold'       => new HoldPanel(on: true, message: 'New rates on Monday.', by: 'boss@speedat.test', since: '2026-09-23T08:00:00.000Z', isOwner: false),
        ], owner: false);

        $this->assertStringNotContains('>Publish version 12<', $html, 'no publish button');
        $this->assertStringContains('An owner publishes this.', $html);
        $this->assertStringNotContains('Resume showing prices', $html, 'no resume button');
        $this->assertStringContains('An owner resumes prices.', $html);
        $this->assertStringContains('You can edit and stage changes; an owner publishes them.', $html);
        // The editor may still stage: the draft form is not read-only.
        $this->assertStringContains('formaction="' . Routes::ADD_DESTINATION . '" name="add" value="1">', $html, 'Add country is still enabled');
    }

    /** Read-only means every control is refused, not merely hidden. */
    public function testAReadOnlyEditorDisablesEveryControl(): void
    {
        $html = $this->render(['readOnly' => true]);

        $this->assertStringContains('name="dest[ca][name]" value="Canada" maxlength="120"' . "\n" . '                   aria-label="Destination name" form="row-ca" disabled', $html);
        $this->assertStringContains('name="add" value="1" disabled', $html);
        $this->assertStringContains(
            'name="dest[ca][rates][exp][grid][1]" value="5220" placeholder="-"' . "\n"
            . '                           aria-label="Canada Express 1 kg price" form="row-ca" disabled',
            $html,
            'a price box'
        );
        $this->assertStringContains('aria-label="Save Canada" disabled', $html, "the row's own Save is refused too");
        $this->assertStringContains('aria-label="Express fill: the 1 kg price" form="row-ca" disabled>', $html, 'the fill boxes are refused too');
        $this->assertStringContains('value="all:ca:exp" data-fill="all" disabled>', $html, 'and so are the fill buttons');
        // A reader who may not edit is not offered a control that copies prices.
        $this->assertStringNotContains('data-copy-tool', $html, 'no copy control in a read-only editor');
    }

    /* ---------------- the hold ---------------- */

    public function testTheHoldButtonAndItsDialogAreBothInThePage(): void
    {
        $html = $this->render();

        // The button says what it does in words (the hold block was reworded
        // so it no longer reads as decoration), and it is still a link.
        $this->assertStringContains('<strong class="hold-now">Prices are showing on the website</strong>', $html);
        $this->assertStringContains(
            'href="' . Routes::holdDialog() . '" data-hold-open>Take prices off the website</a>',
            $html,
            'the button is a link, so it works without JavaScript'
        );
        $this->assertStringContains('Take every price off the website now?', $html);
        $this->assertStringContains('maxlength="' . HoldPanel::MESSAGE_MAX . '"', $html, 'the message is capped at the column width');
        $this->assertStringContains('<input type="hidden" name="on" value="1">', $html);
        $this->assertStringContains('data-hold-count="hold-count"', $html);
    }

    public function testWhileHeldThePageSaysSoAndOffersTheWayBack(): void
    {
        $html = $this->render([
            'hold' => new HoldPanel(on: true, message: 'New rates on Monday.', by: 'boss@speedat.test', since: '2026-09-23T08:00:00.000Z', isOwner: true),
        ]);

        $this->assertStringContains('<span class="pill hold">on hold</span>', $html);
        $this->assertStringContains('<strong>Prices are on hold.</strong>', $html);
        $this->assertStringContains('New rates on Monday.', $html, 'the message customers are shown');
        $this->assertStringContains('by boss@speedat.test', $html);
        $this->assertStringContains('>Resume showing prices</button>', $html);
        $this->assertStringContains('<input type="hidden" name="on" value="0">', $html);
        $this->assertStringContains('customers see no prices', $html, 'the dashboard tile agrees');
    }

    /* ---------------- the rest of the page ---------------- */

    /**
     * Two tiles: what is waiting to publish, and new leads. The sheets counter
     * went with the Excel feature; the live version moved out of a tile into
     * the one-sentence status banner under the heading.
     */
    public function testThereAreTwoDashboardTilesAndTheLiveVersionIsInTheBanner(): void
    {
        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'class="tile'), 'two tiles');
        $this->assertStringContains('<span class="eyebrow">Unpublished</span>', $html);
        $this->assertStringContains('customers still see version 11', $html);
        $this->assertStringContains('<span class="eyebrow">New leads</span>', $html);
        $this->assertStringContains('<a class="tile tone-hot" href="' . Routes::INBOX . '">', $html, 'the leads tile is a link to the inbox');
        $this->assertStringContains('Customers see <strong>version 11</strong>.', $html, 'the banner names the live version');

        $none = $this->render(['changeCount' => 0]);
        $this->assertStringContains('<span class="eyebrow">Nothing waiting</span>', $none);
        $this->assertStringContains('the editor matches version 11', $none);
    }

    /** Bulk adjust posts to its own action and re-states nothing the server will not check. */
    public function testBulkAdjustIsItsOwnFormWithServiceAndFieldTicks(): void
    {
        $html = $this->render();

        $this->assertStringContains('action="' . Routes::BULK . '" data-bulk-form', $html);
        $this->assertStringContains('name="services[]" value="exp"', $html);
        $this->assertStringContains('name="fields[]"', $html);
        $this->assertStringContains('name="roundTo"', $html);
        $this->assertStringContains('>Apply to editor</button>', $html);
    }

    /** Test a price posts, because the point is what the SERVER charges. */
    public function testTestAPricePostsToTheServer(): void
    {
        $html = $this->render();

        $this->assertStringContains('action="' . Routes::TEST . '"', $html);
        $this->assertStringContains('Uses the rates in the editor, including unpublished changes', $html);
        $this->assertStringContains('Enter a destination and weight.', $html);
        $this->assertStringContains('name="destId"', $html);
        $this->assertStringContains('name="pieces"', $html);
    }

    /** Settings and Website pages post with the editor, so one publish covers both. */
    public function testSettingsAndPagesBelongToTheEditorForm(): void
    {
        $html = $this->render();

        $this->assertStringContains('name="settings[currency]" value="PKR"', $html);
        $this->assertStringContains('name="content[faq]"', $html);
        $this->assertTrue(substr_count($html, 'form="editor"') > 40, 'the settings and content fields are associated with the editor');
        $this->assertStringContains('Publishing rates publishes these too.', $html);
    }

    /** History restores into the editor; it does not publish. */
    public function testHistoryRestoresIntoTheEditorRatherThanGoingLive(): void
    {
        $html = $this->render();

        $this->assertStringContains('action="' . Routes::RESTORE . '"', $html);
        $this->assertStringContains('>Restore into editor</button>', $html);
        $this->assertStringContains('<strong class="ver">v11</strong>', $html);
        $this->assertStringContains('<span class="tag">live</span>', $html, 'the live version has no Restore button');
        // Two buttons on the older version now: Restore (into the editor) and
        // Roll back (onto the website). Both carry the version as their value.
        $this->assertSame(2, substr_count($html, 'name="version" value="10"'), 'the older version offers restore and roll back');
        $this->assertSame(0, substr_count($html, 'name="version" value="11"'), 'the live version offers neither');
    }

    /* ---------------- rolling back ---------------- */

    /**
     * RESTORE PUTS AN OLD VERSION IN THE EDITOR; ROLL BACK PUTS IT ON THE
     * WEBSITE. The second one exists because the first one is four steps at
     * the moment somebody has realised the site is quoting the wrong prices.
     */
    public function testRollBackIsOfferedToTheOwnerAndAsksBeforeItActs(): void
    {
        $html = $this->render();

        $this->assertStringContains('action="' . Routes::ROLLBACK . '"', $html, 'the roll-back form');
        $this->assertStringContains('>Roll back</button>', $html);
        $this->assertStringContains('aria-label="Roll back to version 10"', $html);
        // The button that asks carries NO confirm: the first press writes
        // nothing at all and comes back with the question.
        $this->assertStringNotContains('name="confirm"', $html, 'the list must not confirm anything by itself');
    }

    /** An editor may not publish, so an editor is not offered a roll-back. */
    public function testAnEditorIsOfferedNoRollBackAtAll(): void
    {
        $html = $this->render(['canPublish' => false], false);

        $this->assertStringNotContains('>Roll back</button>', $html);
        $this->assertStringNotContains('action="' . Routes::ROLLBACK . '"', $html);
        $this->assertStringContains('>Restore into editor</button>', $html, 'staging is still allowed');
    }

    /**
     * THE QUESTION SAYS WHAT CUSTOMERS WILL SEE, AND WHEN. It is a real form
     * on the page rather than a window.confirm(), which is gone the moment
     * JavaScript is off and could not say any of this.
     */
    public function testTheRollBackQuestionNamesTheVersionAndTheEffectOnCustomers(): void
    {
        $html = $this->render(['rollback' => $this->rollbackAsk()]);

        $this->assertStringContains('Roll back to version 10?', $html);
        $this->assertStringContains('Customers see its prices immediately', $html);
        $this->assertStringContains('name="confirm" value="1"', $html, 'only the second post acts');
        $this->assertStringContains('>Roll back to version 10</button>', $html);
        $this->assertStringContains('#sec-history">Cancel</a>', $html, 'and there is a way out of the question');
    }

    /** A roll-back never lifts the price hold, so the question says so. */
    public function testTheRollBackQuestionSaysTheHoldStaysOn(): void
    {
        $html = $this->render(['rollback' => $this->rollbackAsk(['holdOn' => true])]);

        $this->assertStringContains('Prices are on hold, and rolling back does not put them back', $html);
    }

    /**
     * A VERSION THAT NO LONGER PASSES THE CHECKS IS NOT OFFERED A BUTTON, and
     * the validator's own sentences say why. `Repo::rollbackTo()` refuses it
     * anyway; this is the owner reading the reason while he decides rather
     * than after being told no.
     */
    public function testAVersionThatWouldNotPublishIsRefusedWithItsReasons(): void
    {
        $html = $this->render(['rollback' => $this->rollbackAsk([
            'problems' => ['Canada is shown on the site but has no prices.'],
        ])]);

        $this->assertStringContains('Version 10 cannot be put back', $html);
        $this->assertStringContains('Canada is shown on the site but has no prices.', $html);
        $this->assertStringNotContains('name="confirm" value="1"', $html, 'no button on a version that would fail');
        $this->assertStringContains('>Restore into editor</button>', $html, 'the way forward is still offered');
    }

    /** A version number that was never published is answered, not ignored. */
    public function testAnUnknownVersionSaysSoAndOffersNothing(): void
    {
        $html = $this->render(['rollback' => $this->rollbackAsk(['version' => 9999, 'unknown' => true])]);

        $this->assertStringContains('That version was never published', $html);
        $this->assertStringNotContains('name="confirm" value="1"', $html);
    }

    /** The freshness line warns when the live version is a week old. */
    public function testAStaleLiveVersionIsCalledOut(): void
    {
        $html = $this->render(['liveAgeDays' => 9, 'live' => $this->live()]);

        $this->assertStringContains('class="meta warn"', $html);
        $this->assertStringContains('Rates were last published 9 days ago', $html);
        $this->assertStringContains('key them in below and publish', $html, 'and it says what to do, not "import them"');
        $this->assertSaysNothingAboutSheets($html, 'the stale notice');
    }
}
