<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\DiffResult;
use Speedat\Domain\SiteDoc\Draft;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Validate;
use Speedat\View\Admin\BulkForm;
use Speedat\View\Admin\Csv;
use Speedat\View\Admin\DestinationRow;
use Speedat\View\Admin\DiffLine;
use Speedat\View\Admin\HoldPanel;
use Speedat\View\Admin\RatesPage;
use Speedat\View\Admin\Review;
use Speedat\View\Admin\RollbackAsk;
use Speedat\View\Admin\Site;
use Speedat\View\Admin\TestPricePanel;
use Speedat\View\Admin\VersionRow;

/**
 * The rates editor, assembled.
 *
 * EVERY SCREEN IN THE EDITOR IS THIS ONE SCREEN. `/admin` and
 * `/admin/rates/review` are the same page rendered from the same draft, and the
 * eight actions that post to it all end in a redirect back here - so there is
 * exactly one place that decides what the editor looks like, and a reload after
 * any of them shows the truth rather than re-running the action.
 *
 * WHAT "CHANGED" MEANS IS THE DOMAIN'S ANSWER, NOT THE BROWSER'S. The React
 * editor kept the whole document in the page and diffed it there; here
 * `Domain\SiteDoc\Diff` compares the stored draft with the live version, and the
 * rows, the change count, the review list and the version history all read that
 * one comparison. A row is marked changed because the diff says its id changed,
 * which is the same reason the review card lists it.
 *
 * THE SEARCH BOX HIDES ROWS, IT DOES NOT DROP THEM. A filtered row is still
 * rendered, still carries its fields and is still posted, so typing a country's
 * name and pressing Save cannot delete the other forty. (`DestinationRow` takes
 * `hidden` for exactly this.)
 */
final class EditorPage
{
    /** How many versions the history lists. */
    private const HISTORY = 20;

    /**
     * Render the editor, with or without the review card under the table.
     *
     * `$test` is the priced result of "Test a price", which is the one thing on
     * this page the server computes from a post without changing anything - so it
     * is handed in rather than read from the draft.
     */
    public static function render(App $app, AdminUser $admin, bool $withReview = false, ?TestPricePanel $test = null): Response
    {
        // BEFORE ANYTHING READS THE DRAFT: one that fell behind the live
        // version is carried onto it here, so the hidden fields, the table, the
        // change count and the review card all describe a draft that
        // publishing cannot use to undo a newer version. Nothing to do costs
        // one indexed read.
        //
        // Once per request, never waiting (0) for the publisher's lock - a
        // publish in progress means "try on the next page view", and the cron
        // does the waiting - and never able to take the editor down with it:
        // the owner can always see the page, with the "started from version N"
        // warning as the fallback.
        try {
            Repo::catchUpDraft(0);
        } catch (\Throwable $e) {
            Log::exception('draft_catchup_failed', $e);
        }

        $draft = Repo::getDraft();

        return $app->page('admin/rates', [
            'page'     => self::build($app, $admin, $withReview, $test),
            'shell'    => Screen::shell($admin, 'rates', true),
            'theme'    => Screen::theme($app),
            'newLeads' => Screen::counts()->leadsNew(),
            // The two values the forms on this page have to hand back
            // unchanged. They are page variables rather than fields on the view
            // model because they are not about what the editor LOOKS like: they
            // are the answer to "which draft, and which published version, is
            // this screen editing", and the templates print them into hidden
            // inputs. See `openedAtBase()` and `Http\Admin\DraftForm`.
            'baseVersion'   => self::openedAtBase($app, $draft),
            'draftRevision' => $draft->revision,
        ]);
    }

    /**
     * THE PUBLISHED VERSION THIS EDITOR WAS OPENED AT.
     *
     * Not the live version as it stands now. The difference is the whole point:
     * `Repo::publishVersion()` refuses a publish whose `expectedBase` is not the
     * live version, and a screen that re-reads the live version every time it is
     * drawn always hands back a number that agrees - so the refusal could never
     * fire, and the owner could publish on top of a colleague's version without
     * being told. The number therefore has to be minted when the editor is first
     * opened and then carried, unchanged, through every save and every redirect.
     *
     * WHERE IT COMES FROM, IN ORDER:
     *   1. `?base=` on the URL, which `RatesController::withBase()` put there
     *      when the post that led here carried one. That is the carried value.
     *   2. otherwise the draft's own `baseVersion` - the version the working
     *      copy was branched from - which is the right answer the first time
     *      somebody opens the editor, and the only honest one when the draft
     *      has just been re-branched by a publish or a discard.
     *
     * A carried number that is AHEAD of the draft's base is not accepted: it
     * could only come from a hand-edited URL, and "expect a version that does
     * not exist yet" would refuse every publish rather than allowing a bad one.
     * A carried number BEHIND it is kept exactly as it is - that is a stale
     * editor, which is the case this whole mechanism exists to catch.
     */
    public static function openedAtBase(App $app, ?Draft $draft = null): int
    {
        $draft ??= Repo::getDraft();
        $carried = Notice::number($app->request, 'base');

        // A number carried from before the draft was caught up onto a newer
        // version (`Repo::catchUpDraft()`) describes a draft that no longer
        // exists: kept, it would make the review card warn that the draft is
        // behind when it has just been brought level.
        $note = Repo::rebaseNote();
        if ($note !== null && $carried > 0 && $carried < $note->to) {
            return $draft->baseVersion;
        }

        return $carried > 0 && $carried <= $draft->baseVersion ? $carried : $draft->baseVersion;
    }

    /**
     * The catch-up could not be done (`Repo::rebaseRefused()`), in words, or ""
     * - the draft is still behind, and the owner has to know why before
     * publishing, because every line on the review card will be applied.
     */
    private static function refusedSentence(): string
    {
        $r = Repo::rebaseRefused();
        if ($r === null) {
            return '';
        }

        $why = match ($r['reason']) {
            'base_missing'  => 'version ' . $r['from'] . ', which this draft was started from, no longer exists',
            'bad_base', 'bad_live' => 'a stored version is damaged (' . $r['detail'] . ')',
            default         => 'the combined result would not be valid (' . $r['detail'] . ')',
        };

        return 'Version ' . $r['to'] . ' went live after this draft was started, and it could not be combined with your draft automatically: '
            . $why . '. The review list shows every difference from version ' . $r['to'] . ', and publishing applies all of them -'
            . ' including undoing anything version ' . $r['to'] . ' changed. If you do not recognise lines there, Discard all changes and key your edits again.';
    }

    /** The whole view model. */
    public static function build(App $app, AdminUser $admin, bool $withReview = false, ?TestPricePanel $test = null): RatesPage
    {
        $draft = Repo::getDraft();
        $live  = Repo::getLatestVersion() ?? PublishedVersion::sample();

        $draftSite = Site::fromArray($draft->data->toArray());
        $liveSite  = Site::fromArray($live->data->toArray() + [
            'version'     => $live->version,
            'publishedAt' => $live->publishedAt,
        ]);

        $diff     = Diff::of($live->data, $draft->data);
        $rebased  = Repo::rebaseNote();
        $versions = Repo::listVersions(self::HISTORY);
        $hold     = Live::hold();

        $filter = $app->request->query('q', '', 80);
        $open   = $app->request->query('open', '', 64);

        [$bulkKind, $bulkMessage] = Notice::bulk($app);

        $draftMoved = in_array($app->request->query('err', '', 24), ['draftmoved', 'draftrebased'], true);

        [$noticeKind, $noticeText] = self::outcome($app, $hold->on);

        $publishedBy = '';
        $rows        = [];
        foreach ($versions as $version) {
            if ($version->version === $live->version) {
                $publishedBy = $version->publishedBy;
            }
            $rows[] = [
                'version'     => $version->version,
                'publishedAt' => $version->publishedAt,
                'publishedBy' => $version->publishedBy,
                'summary'     => $version->summary,
            ];
        }

        return new RatesPage(
            draft: $draftSite,
            live: $liveSite,
            rows: self::rows($draftSite, $liveSite, $diff, $filter, $open),
            versions: VersionRow::all($rows, $live->version),
            hold: new HoldPanel(
                on: $hold->on,
                message: $hold->message,
                by: $hold->by,
                since: $hold->since ?? '',
                isOwner: $admin->isOwner(),
                dialogOpen: $app->request->query('hold', '', 4) === '1',
            ),
            bulk: new BulkForm(
                services: $draftSite->services(),
                servicesOn: self::allOn($draftSite),
                messageKind: $bulkKind,
                message: $bulkMessage,
            ),
            test: $test ?? TestPricePanel::blank($draftSite),
            changeCount: $diff->count,
            draftUpdatedBy: $draft->updatedBy,
            draftUpdatedAt: $draft->updatedAt,
            viewerEmail: $admin->email,
            canPublish: $admin->isOwner(),
            livePublishedBy: $publishedBy,
            liveAgeDays: self::ageDays($live),
            filter: $filter,
            // THE COPY BUTTON COPIES WHAT THE DOWNLOAD GIVES: the live
            // version, under the same "# Speedat published rates - version N"
            // row. It used to copy the DRAFT with no first line, so an owner
            // who raised a price without publishing could paste it to a
            // carrier as the rate, beside a download button that said
            // otherwise. The draft is still exportable, at `?draft=1`, where
            // it says so in the file. No BOM here - see `Csv::BOM`.
            csv: Csv::document(Csv::liveComment($live->version, $live->publishedAt, Clock::utcNow()), $liveSite),
            review: $withReview ? self::review($app, $draft, $live, $diff, $hold->on) : null,
            publishedNotice: $noticeText,
            publishedNoticeKind: $noticeKind,
            toast: Notice::toast($app),
            readOnly: false,
            // Whose work is on the screen. One draft row, several admins: the
            // sentence is the domain's own so that the page and the refusal
            // cannot describe the same row in two different ways.
            draftEditedBy: $draft->editedBy(),
            draftMoved: $draftMoved,
            rollback: self::rollbackAsk($app, $hold->on, $live->version, $diff->count),
            rebased: $rebased,
            rebaseLines: $rebased?->openLines($draft->data->toArray(), $live->data->toArray()) ?? [],
            rebaseRefused: self::refusedSentence(),
        );
    }

    /**
     * "Roll back to version 7?" - the card, when one has been asked for.
     *
     * IT RUNS THE VALIDATOR HERE, WHILE NOTHING HAS BEEN WRITTEN.
     * `Repo::rollbackTo()` refuses a version that no longer passes the checks
     * a publish has to pass, and its `RollbackRefused::$reasons` carries the
     * validator's own sentences - "Canada is shown on the site but has no
     * prices." Those sentences cannot come back through the redirect that
     * follows a refusal: `Notice` carries codes and never text, deliberately,
     * so that nothing a person typed can be put on the owner's screen through
     * a URL. So the same check is run at the moment the question is asked, and
     * the owner reads the reasons while deciding rather than after being
     * refused. The refusal itself stays exactly where it is, as the floor
     * under this.
     *
     * It is the same answer the repository will give. `rollbackTo()` validates
     * the document it would publish - the old version's rates carrying the
     * live document's "rates are live" flag and remembered layouts - and
     * `Validate::errors()` reads neither of those two fields, so validating
     * the stored version here cannot disagree with it.
     *
     * TWO MORE FACTS GO ON THE CARD. `$liveVersion` is the version the
     * question is being asked about undoing; the confirm form hands it back
     * as `expectedLive`, so a version a colleague publishes while the owner
     * is reading is not silently undone too. `$draftChanges` is the count of
     * unpublished edits a roll-back will replace, so the question no longer
     * promises "nothing is lost" over the top of them.
     */
    private static function rollbackAsk(App $app, bool $holdOn, int $liveVersion = 0, int $draftChanges = 0): ?RollbackAsk
    {
        $version = Notice::rollbackAsked($app);

        if ($version <= 0) {
            return null;
        }

        $old = Repo::getVersion($version);

        return new RollbackAsk(
            version: $version,
            question: Notice::rollbackQuestion($app, $draftChanges),
            problems: $old === null ? [] : Validate::errors($old->data),
            holdOn: $holdOn,
            unknown: $old === null,
            liveVersion: $liveVersion,
            draftChanges: $draftChanges,
            // Only true when the refusal is the one that redrew this card;
            // the card says it in its own words, beside the button.
            moved: $app->request->query('err', '', 24) === 'rollmoved',
        );
    }

    /**
     * The review card: what is about to go live, what blocks it, what is merely
     * odd, and the outcome of a publish that was refused.
     */
    public static function review(App $app, Draft $draft, PublishedVersion $live, DiffResult $diff, bool $holdOn): Review
    {
        return new Review(
            nextVersion: $live->version + 1,
            errors: Validate::errors($draft->data),
            lines: DiffLine::all(array_map(static fn ($line): array => $line->toArray(), $diff->lines)),
            warnings: Validate::warnings($draft->data),
            holdOn: $holdOn,
            // The tick is offered only while the site still shows sample rates;
            // once it is live there is nothing to go live about.
            offerGoLive: !$live->data->live,
            goLive: true,
            resume: true,
            message: Notice::reviewMessage($app),
        );
    }

    /**
     * One row per destination in the draft, with the live row beside it for Undo.
     *
     * @return list<DestinationRow>
     */
    private static function rows(Site $draft, Site $live, DiffResult $diff, string $filter, string $open): array
    {
        $services = $draft->services();
        $kgs      = $draft->isGrid() ? $draft->gridWeights() : [];
        $needle   = Str::nameKey($filter);

        $rows     = [];
        $position = 0;

        foreach ($draft->destinations() as $dest) {
            $rows[] = DestinationRow::build(
                draft: $dest,
                live: $live->destination($dest->id),
                services: $services,
                kgs: $kgs,
                position: $position,
                changed: isset($diff->byDest[$dest->id]),
                open: $open !== '' && $open === $dest->id,
                hidden: $needle !== '' && !str_contains(Str::nameKey($dest->name), $needle),
            );

            $position++;
        }

        return $rows;
    }

    /** Whole days since the live version was published; 0 when nothing ever was. */
    private static function ageDays(PublishedVersion $live): int
    {
        if ($live->version === 0) {
            return 0;
        }

        try {
            $at = new \DateTimeImmutable($live->publishedAt);
        } catch (\Exception) {
            return 0;
        }

        $days = (int) floor((Clock::timestamp() - $at->getTimestamp()) / 86400);

        return max(0, $days);
    }

    /**
     * WHAT THE LAST POST DID, AND IN WHAT TONE.
     *
     * One line, in the order that matters: a refusal first, then what did not
     * save, then what did. The tone travels with it because it used to not,
     * and the screen printed every one of these inside `notice ok` - so "that
     * was more than the server will accept in one go, so nothing was saved"
     * arrived in the green box the same page uses for "version 12 is live".
     * A refusal the colour of a success is a refusal people read as a
     * success.
     *
     * A DRAFT CONFLICT IS STILL SAID HERE as well as in the box above it. The
     * box names who has the draft and carries the Reload; this is the line
     * every other refusal arrives on, and taking the conflict out of it would
     * make that one refusal the exception. What mattered was the colour: both
     * are now the same red, where before the box was red and this was green.
     *
     * @return array{0:string,1:string} kind (ok|warn|err) and the sentence
     */
    private static function outcome(App $app, bool $holdOn): array
    {
        $error = Notice::error($app);

        if ($error !== '') {
            return ['err', $error];
        }

        $kept = Notice::kept($app);

        if ($kept !== '') {
            return ['warn', $kept];
        }

        // A country saved with no name is its own sentence, not one of the
        // "boxes that did not hold a number" above - see `DraftForm::destination()`.
        $noname = Notice::noname($app);

        if ($noname !== '') {
            return ['warn', $noname];
        }

        // The hold is handed in because a roll-back never lifts it, and the
        // owner has to be told that on the screen that says it worked rather
        // than by a customer who still sees no prices. That caveat is not a
        // success, so it is not painted as one.
        $rolledBack = Notice::rolledBack($app, $holdOn);

        if ($rolledBack !== '') {
            return [$holdOn ? 'warn' : 'ok', $rolledBack];
        }

        $published = Notice::published($app);

        return $published === '' ? ['ok', ''] : ['ok', $published];
    }

    /** Bulk adjust starts with every service ticked, as the React form did. */
    /** @return array<string,bool> */
    private static function allOn(Site $draft): array
    {
        $on = [];
        foreach ($draft->services() as $service) {
            $on[$service->id] = true;
        }

        return $on;
    }

    /** The draft's "last saved" stamp as admin.js reprints it after an autosave. */
    public static function savedAt(Draft $draft): string
    {
        return Format::fmtDateTime($draft->updatedAt);
    }
}
