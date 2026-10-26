<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Audit;
use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Core\Request;
use Speedat\Core\Str;

/**
 * The store for the published site document and the admin draft. Port of
 * src/lib/site/repo.ts onto MySQL.
 *
 * Server-side only. Every read runs the stored JSON through `Migrate`, so a
 * version published before a field existed still loads.
 *
 * WHAT ACTUALLY GATES A PUBLISH, stated precisely because it used to be stated
 * wrongly. This class ran `Schema::firstIssue()` on `$args->data->toArray()`
 * and claimed that check as the last line of defence. It is not: `$args->data`
 * is a `SiteData`, and `SiteData::fromArray()` has already coerced whatever it
 * was built from into a schema-valid shape, so that call can only catch what
 * survives normalisation (an over-long `content.phone2` does; a `services` key
 * that arrived as a string does not - it is an empty list by then). The real
 * guard was the admin form's field allow-list. Both checks now run, and the
 * one that matters reads `PublishArgs::$raw`, the document as it was
 * submitted: see the note on that property. `Validate::errors()` is the
 * business half and is the caller's to run - `rollbackTo()` below runs it.
 *
 * SERIALISING PUBLISHERS. Postgres had `pg_advisory_xact_lock`; MySQL has no
 * transaction-scoped advisory lock, so this uses a named lock (`GET_LOCK`)
 * taken BEFORE the transaction and released AFTER the commit. The order is the
 * whole point: release it inside the transaction and a second publisher would
 * read the highest version number before the first one's row is visible, and
 * two admins clicking Publish together would fight over the same number. The
 * PRIMARY KEY on `versions.version` is the backstop - a duplicate is a loud
 * error, never a silent overwrite of somebody's rates.
 */
final class Repo
{
    private const DRAFT_ID = 1;

    /** The app_settings row that remembers the last catch-up (`catchUpDraft()`). */
    private const REBASE_NOTE = 'draft_rebase';

    /** The app_settings row that remembers a catch-up that could not be done, and why. */
    private const REBASE_REFUSED = 'draft_rebase_refused';

    /** Who the catch-up writes the draft as; `Draft::editedBy()` words it for people. */
    public const SYSTEM = 'system';

    /** The named lock every publisher queues on. */
    private const PUBLISH_LOCK = 'speedat.site.publish';

    /** Seconds a publisher waits for its turn before giving up. */
    private const LOCK_TIMEOUT = 10;

    /** Column widths from db/migrations/0001_init.sql. */
    private const BY_MAX     = 190;
    private const SOURCE_MAX = 255;

    /* --------------------------------------------------------- reading */

    /** The document customers see right now, or null on an empty database. */
    public static function getLatestVersion(): ?PublishedVersion
    {
        $row = Db::one('SELECT version, published_at, data FROM versions ORDER BY version DESC LIMIT 1');

        return $row === null ? null : self::toPublished($row);
    }

    public static function getVersion(int $version): ?PublishedVersion
    {
        $row = Db::one('SELECT version, published_at, data FROM versions WHERE version = ?', [$version]);

        return $row === null ? null : self::toPublished($row);
    }

    /** @return list<VersionMeta> newest first */
    public static function listVersions(int $limit = 20): array
    {
        $limit = max(1, min($limit, 500));

        $rows = Db::all(
            'SELECT version, published_at, published_by, source, summary, change_count'
            . ' FROM versions ORDER BY version DESC LIMIT ?',
            [$limit]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = new VersionMeta(
                version: (int) $row['version'],
                publishedAt: self::iso($row['published_at']),
                publishedBy: (string) $row['published_by'],
                source: (string) $row['source'],
                summary: (string) $row['summary'],
                changeCount: (int) $row['change_count'],
            );
        }

        return $out;
    }

    /* ----------------------------------------------------------- draft */

    /** The working copy; branched from the live version on first use. */
    public static function getDraft(): Draft
    {
        $row = self::draftRow();
        if ($row !== null) {
            return self::toDraft($row);
        }

        $live = self::getLatestVersion();
        if ($live === null) {
            throw new \RuntimeException('No published version exists; run the seed first');
        }

        // INSERT IGNORE, not INSERT: two requests may branch the draft at the
        // same moment and the loser must read the winner's row, not crash.
        Db::exec(
            'INSERT IGNORE INTO draft (id, base_version, data, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)',
            [self::DRAFT_ID, $live->version, Db::json($live->data->toArray()), Clock::sql(), 'system']
        );

        $row = self::draftRow();
        if ($row === null) {
            throw new \RuntimeException('The draft could not be created.');
        }

        return self::toDraft($row);
    }

    /**
     * Write the working copy, and say what it now is.
     *
     * `$expectedRevision` is the fingerprint the editor was RENDERED with (see
     * `Draft`). Pass it and a save whose row has moved underneath the screen
     * is refused with `DraftConflict` instead of overwriting whoever wrote it
     * last - which is the whole difference between "two admins share a draft"
     * and "two admins take turns losing each other's prices". Pass null and
     * this is the old last-writer-wins behaviour, which is right for a seed, a
     * restore and a script, where there is no screen to have gone stale.
     *
     * NULL MEANS "NO SCREEN", NOT "NO REVISION SENT". An empty string is a
     * fingerprint that matches nothing, so a form that posts `revision=""`
     * fails closed rather than silently saving - which is what a missing
     * hidden field should do. The HTTP layer decides which of the two it has.
     *
     * @throws DraftConflict when `$expectedRevision` is not what is stored
     */
    public static function saveDraft(SiteData $data, string $by, ?string $expectedRevision = null): Draft
    {
        // Reading it first is what creates the row when there is none, and what
        // carries the base version forward: a save is not a re-base. It happens
        // OUTSIDE the transaction below because branching reads the live
        // version, and holding the draft row while doing that would widen the
        // lock for no gain.
        self::getDraft();

        $json = Db::json($data->toArray());
        $who  = Str::limit($by, self::BY_MAX);
        $at   = Clock::sql();

        return Db::transaction(static function () use ($json, $who, $at, $expectedRevision): Draft {
            // FOR UPDATE, not a plain read. Without the row lock two admins
            // can both read the same fingerprint, both find it current, and
            // both write - which is exactly the race the fingerprint exists to
            // stop, reintroduced one layer down.
            $row = Db::one(
                'SELECT base_version, data, updated_at, updated_by FROM draft WHERE id = ? FOR UPDATE',
                [self::DRAFT_ID]
            );

            // Only reachable when somebody pressed Discard between the read
            // above and this one. Saying so beats repeating "could not be
            // created" about a row that was created and then thrown away.
            if ($row === null) {
                throw new \RuntimeException('The draft was discarded while it was being saved.');
            }

            $current = self::toDraft($row);

            if ($expectedRevision !== null && !hash_equals($current->revision, $expectedRevision)) {
                // Thrown inside the transaction, so the rollback is what
                // guarantees "nothing was written" rather than a promise in a
                // comment.
                throw new DraftConflict($current);
            }

            Db::exec(
                'UPDATE draft SET data = ?, updated_at = ?, updated_by = ?, base_version = ? WHERE id = ?',
                [$json, $at, $who, $current->baseVersion, self::DRAFT_ID]
            );

            // Built through the same reader a later getDraft() will use, so
            // the fingerprint handed back is byte-for-byte the one the next
            // save will be checked against.
            return self::toDraft([
                'base_version' => $current->baseVersion,
                'data'         => $json,
                'updated_at'   => $at,
                'updated_by'   => $who,
            ]);
        });
    }

    /** Throw the draft away; the next `getDraft()` re-branches from the live version. */
    public static function discardDraft(): void
    {
        Db::exec('DELETE FROM draft WHERE id = ?', [self::DRAFT_ID]);
        self::clearRebaseState();
    }

    /**
     * BRING A DRAFT THAT FELL BEHIND ONTO THE LIVE VERSION, keeping the
     * owner's edits. Returns what it did, or null when there was nothing to do.
     *
     * A draft is behind when a version was published that it was not branched
     * from. Every publish through `publishVersion()` re-bases the draft in the
     * same transaction, so in normal use this never fires; it exists for the
     * day a version arrives some other way. speedat.co had that day on
     * 2026-10-11 (version 18 written straight into the table), and a behind
     * draft then blocked every publish for two weeks and, published as it
     * stood, would have undone 18. See `Rebase` for the merge rules.
     *
     * Called where the draft is looked at - the editor, on every render - and
     * by the hourly cron, so a behind draft is caught even if nobody opens the
     * admin. Under the publisher's lock and a row lock, so it cannot interleave
     * with a publish or a save; the draft's fingerprint changes, so an editor
     * open in another tab is told to reload rather than saving over it.
     *
     * When the version the draft came from is gone, nothing is merged: there is
     * no way to tell whose change is whose, and the review card's "this draft
     * was started from version N" warning is the honest fallback.
     */
    public static function catchUpDraft(int $wait = self::LOCK_TIMEOUT): ?RebaseNote
    {
        $behind = Db::one('SELECT d.base_version AS base, (SELECT MAX(version) FROM versions) AS live FROM draft d WHERE d.id = ?', [self::DRAFT_ID]);
        if ($behind === null || $behind['live'] === null || (int) $behind['base'] === (int) $behind['live']) {
            return null;
        }

        // Refused before for exactly this pair of versions: nothing has changed
        // that could make it work now, so the editor does not queue on the
        // publisher's lock on every page view to be refused again. A publish,
        // discard or restore clears the refusal (see clearRebaseState()).
        $refused = self::rebaseRefused();
        if ($refused !== null && $refused['from'] === (int) $behind['base'] && $refused['to'] === (int) $behind['live']) {
            return null;
        }

        // The editor passes 0: a page view must never wait ten seconds, or fail,
        // because somebody is publishing. It simply tries again next time; the
        // cron waits the full time.
        if (!self::tryLock($wait)) {
            return null;
        }

        try {
            $note = Db::transaction(static function (): ?RebaseNote {
                $row = Db::one(
                    'SELECT base_version, data, updated_at, updated_by FROM draft WHERE id = ? FOR UPDATE',
                    [self::DRAFT_ID]
                );
                $live = self::getLatestVersion();

                if ($row === null || $live === null || (int) $row['base_version'] === $live->version) {
                    return null;
                }

                $draft = self::toDraft($row);

                if ($draft->baseVersion > $live->version) {
                    // AHEAD, not behind: the version the draft came from was
                    // deleted by hand. Nothing can be merged, and left alone
                    // every publish would be refused again. The draft is
                    // re-pointed at the live version with its contents kept,
                    // so whatever differs - including the removed version's
                    // changes - shows as ordinary unpublished changes on the
                    // review card. The author is left as it was: nothing was
                    // brought up to date, and any old note is no longer true.
                    Db::exec(
                        'UPDATE draft SET base_version = ?, updated_at = ? WHERE id = ?',
                        [$live->version, Clock::sql(), self::DRAFT_ID]
                    );
                    self::clearRebaseState();
                    Log::warn('draft_repointed', ['from' => $draft->baseVersion, 'to' => $live->version]);
                    Audit::log(self::SYSTEM, 'draft_repointed', ['from' => $draft->baseVersion, 'to' => $live->version]);

                    return null;
                }

                // BOTH STORED VERSIONS ARE CHECKED AS STORED, before anything is
                // merged: a version row written by hand can be anything ("{}",
                // a half-pasted JSON), and `fromArray()` would quietly turn it
                // into an empty document - merged, that wipes every country out
                // of the draft. The same raw-first check publishVersion() makes.
                $baseRaw = Db::value('SELECT data FROM versions WHERE version = ?', [$draft->baseVersion]);
                if ($baseRaw === null) {
                    return self::refuseRebase($draft->baseVersion, $live->version, 'base_missing', 'version ' . $draft->baseVersion . ' no longer exists');
                }
                $liveRaw = Db::value('SELECT data FROM versions WHERE version = ?', [$live->version]);
                foreach (['base' => $baseRaw, 'live' => $liveRaw] as $which => $raw) {
                    $doc   = Db::unjson($raw);
                    $issue = is_array($doc) && $doc !== [] ? Schema::firstIssue(Migrate::migrate($doc)->toArray()) : 'not a document';
                    if ($issue !== null) {
                        return self::refuseRebase($draft->baseVersion, $live->version, 'bad_' . $which, $issue);
                    }
                }

                $base   = self::getVersion($draft->baseVersion);
                $result = Rebase::merge($base->data->toArray(), $live->data->toArray(), $draft->data->toArray());
                $merged = SiteData::fromArray($result['merged']);
                $issue  = Schema::firstIssue($merged->toArray()) ?? (Validate::errors($merged)[0] ?? null);

                if ($issue !== null) {
                    // Never store a document that could not be published. The
                    // draft stays behind; the editor says why, and the review
                    // card's "started from version N" warning stands.
                    return self::refuseRebase($draft->baseVersion, $live->version, 'merge_invalid', $issue);
                }

                // Written as 'system', not as the last person who typed: the
                // editor then says the draft was brought up to date
                // automatically, and an open tab's refused save does not blame
                // its own owner as "somebody else".
                $at = Clock::sql();
                Db::exec(
                    'UPDATE draft SET data = ?, base_version = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                    [Db::json($merged->toArray()), $live->version, $at, self::SYSTEM, self::DRAFT_ID]
                );
                Db::exec('DELETE FROM app_settings WHERE k = ?', [self::REBASE_REFUSED]);

                $services = [];
                foreach ($live->data->card->services as $service) {
                    $services[$service->id] = $service->name;
                }

                $note = new RebaseNote(
                    from: $draft->baseVersion,
                    to: $live->version,
                    carried: Diff::of($live->data, $merged)->count,
                    conflicts: $result['conflicts'],
                    at: self::iso($at),
                    currency: $live->data->card->settings->currency,
                    services: $services,
                    liveAt: $live->publishedAt,
                );

                // A second catch-up before anybody published keeps what the
                // first one reported: its starting version, and the values it
                // took away from the owner.
                $older = RebaseNote::fromArray(Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ? FOR UPDATE', [self::REBASE_NOTE])));
                if ($older !== null) {
                    $note = $older->followedBy($note);
                }

                if ($note->isEmpty()) {
                    // Nothing carried and nothing clashed: the draft simply
                    // became the live version. A green box saying "0 changes
                    // were kept" would only raise a question.
                    Db::exec('DELETE FROM app_settings WHERE k = ?', [self::REBASE_NOTE]);

                    return $note;
                }

                self::putSetting(self::REBASE_NOTE, $note->toArray(), $at);

                return $note;
            });
        } finally {
            self::unlock();
        }

        if ($note !== null) {
            // The diary keeps EVERY replaced value, in rows small enough that
            // `Audit::log()` never truncates them (it replaces anything over
            // 16 KB with a list of keys). The note in app_settings is deleted at
            // the next publish; this is the copy that survives it.
            $summary = $note->toArray();
            unset($summary['conflicts']);
            Audit::log(self::SYSTEM, 'draft_rebased', $summary + ['clashes' => count($note->conflicts)]);
            foreach (array_chunk($note->conflicts, 25) as $i => $chunk) {
                Audit::log(self::SYSTEM, 'draft_rebase_clashes', ['to' => $note->to, 'part' => $i + 1, 'clashes' => $chunk]);
            }
        }

        return $note;
    }

    /**
     * Why the last catch-up could not be done, for exactly which versions; null
     * when nothing was refused (or it has since been cleared).
     *
     * @return array{from:int, to:int, reason:string, detail:string}|null
     */
    public static function rebaseRefused(): ?array
    {
        $raw = Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ?', [self::REBASE_REFUSED]));

        if (!is_array($raw) || !is_numeric($raw['from'] ?? null) || !is_numeric($raw['to'] ?? null)) {
            return null;
        }

        return [
            'from'   => (int) $raw['from'],
            'to'     => (int) $raw['to'],
            'reason' => is_scalar($raw['reason'] ?? null) ? (string) $raw['reason'] : '',
            'detail' => is_scalar($raw['detail'] ?? null) ? (string) $raw['detail'] : '',
        ];
    }

    /** Remember a refusal (inside the catch-up's transaction), log it, and return the "nothing done" answer. */
    private static function refuseRebase(int $from, int $to, string $reason, string $detail): ?RebaseNote
    {
        self::putSetting(self::REBASE_REFUSED, ['from' => $from, 'to' => $to, 'reason' => $reason, 'detail' => $detail], Clock::sql());
        Log::warn('draft_rebase_refused', ['from' => $from, 'to' => $to, 'reason' => $reason, 'detail' => $detail]);

        return null;
    }

    /** Forget the last catch-up: what it did and what it refused. Every path that replaces the draft calls this. */
    private static function clearRebaseState(): void
    {
        Db::exec('DELETE FROM app_settings WHERE k IN (?, ?)', [self::REBASE_NOTE, self::REBASE_REFUSED]);
    }

    /** @param array<string,mixed> $value */
    private static function putSetting(string $key, array $value, string $at): void
    {
        Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :at, :by)'
            . ' ON DUPLICATE KEY UPDATE v = :v2, updated_at = :at2, updated_by = :by2',
            [
                ':k' => $key, ':v' => Db::json($value), ':at' => $at, ':by' => self::SYSTEM,
                ':v2' => Db::json($value), ':at2' => $at, ':by2' => self::SYSTEM,
            ]
        );
    }

    /** What the last catch-up did, until the next publish or discard; null when there is nothing to say. */
    public static function rebaseNote(): ?RebaseNote
    {
        $raw = Db::value('SELECT v FROM app_settings WHERE k = ?', [self::REBASE_NOTE]);

        return $raw === null ? null : RebaseNote::fromArray(Db::unjson($raw));
    }

    /**
     * Put an old version's rates and text back into the DRAFT, leaving it to
     * be reviewed and published like any other edit. Port of the admin's
     * `restoreVersionAction`, minus the parts that belong to the admin layer
     * (permission, audit, releasing applied import sheets).
     *
     * `live` and the remembered import layouts stay as they are: they are about
     * the site's current state, not about the rates being restored.
     *
     * THIS DOES NOT CHANGE WHAT CUSTOMERS SEE. That is the whole point of it -
     * the button says "Restore into editor" - and it is also why it is not a
     * rollback: after a bad publish the site is still serving the bad prices
     * until somebody reviews and presses Publish. When the answer to a bad
     * publish is "undo it, now", the one-step version is `rollbackTo()`.
     *
     * Returns null when that version does not exist.
     *
     * IT REPLACES THE WHOLE DRAFT, so `$expectedRevision` is the fingerprint
     * of the draft the editor was showing, exactly as `saveDraft()` takes it.
     * It used to run with no revision at all, which meant a colleague's newer
     * draft was overwritten by a press of "Restore into editor" with no word
     * on either screen. Null keeps the old behaviour for a caller with no
     * screen behind it.
     *
     * @throws DraftConflict when `$expectedRevision` is not what is stored
     */
    public static function restore(int $version, string $by, ?string $expectedRevision = null): ?Draft
    {
        $old = self::getVersion($version);
        if ($old === null) {
            return null;
        }

        $current = self::getDraft();

        $restored = self::saveDraft(self::rebuild($old->data, $current->data), $by, $expectedRevision);

        // The draft is now an old version's rates, so a note saying "publishing
        // will not undo version N" is no longer true.
        self::clearRebaseState();

        return $restored;
    }

    /**
     * ROLL BACK to version `$version`: put it back into the draft AND publish
     * it, as one action, with its own line in the history and its own line in
     * the audit log.
     *
     * The admin's Restore button leaves the bad prices live until somebody
     * reviews and publishes, which is the right shape for "let me look at what
     * we used to charge" and the wrong shape for "that publish was wrong, take
     * it off". This is the second one. The owner presses it once and the site
     * is serving version `$version`'s prices before he has let go of the
     * mouse.
     *
     * Refused, with nothing written, when:
     *   - no such version was ever published;
     *   - it is already the live one (a version row that changes nothing is
     *     noise in the history, the same rule the publish screen applies);
     *   - it no longer passes `Validate::errors()`. A field can leave the
     *     product between the publish and the roll-back - the document rate
     *     did - and a version whose only priced service was that one now
     *     prices nothing. Putting it back would take the prices off half the
     *     destinations, silently, which is a worse accident than the one being
     *     undone.
     *
     * THE PRICE HOLD IS NOT TOUCHED. `liftHold: false`, deliberately: somebody
     * put the hold on for a reason this document does not know, and resuming
     * prices as a side effect of an undo is a second decision taken on his
     * behalf without a word on the screen. Whether the prices are still held
     * is recorded in the audit line so the screen can say so.
     *
     * WHICH LIVE VERSION IS BEING UNDONE is `$expectedLive`: the version the
     * question card was drawn against. Without it the live version was read
     * here, at confirm time, and handed to the publisher as `expectedBase` -
     * which always agrees with itself. So if a colleague published a
     * hand-corrected version while the owner was reading the card, the
     * confirm undid that one too, silently, although the comment below said
     * it refused. Null keeps the old "whatever is live now" for a caller with
     * no card behind it (a script).
     *
     * @throws RollbackRefused on any of the three above
     * @throws PublishConflict if somebody published after the question was
     *                         asked - the roll-back is then aimed at a version
     *                         that is no longer the one being undone
     */
    public static function rollbackTo(int $version, string $by, ?Request $request = null, ?int $expectedLive = null): PublishedVersion
    {
        $old = self::getVersion($version);
        if ($old === null) {
            throw RollbackRefused::noSuchVersion($version);
        }

        $live = self::getLatestVersion();

        // Asked first, before the checks below: if the version the owner was
        // asked about is no longer live, "it is already the live one" or "it
        // changes nothing" would be answers about a different question.
        // `publishVersion()` asks again under the lock.
        if ($expectedLive !== null && ($live?->version ?? 0) !== $expectedLive) {
            throw new PublishConflict($live?->version ?? 0);
        }

        // `live` and the remembered import layouts are carried forward from
        // what is on the site now, exactly as restore() carries them: they
        // describe the site's current state, not the rates being put back. A
        // roll-back must not drop the site into sample mode.
        $document = $live === null ? $old->data : self::rebuild($old->data, $live->data);

        // Both of the checks below read THE DOCUMENT THAT WOULD GO LIVE, not
        // the stored version it came from. Those are two different documents
        // by the line above, and checking the wrong one shows up in exactly
        // the case that matters: if the only difference between the old
        // version and the live one is the "rates are live" tick, a diff taken
        // against `$old->data` counts one change and lets a version row
        // through that changes nothing at all.
        $errors = Validate::errors($document);
        if ($errors !== []) {
            throw RollbackRefused::invalid($version, $errors);
        }

        $diff = $live === null ? null : Diff::of($live->data, $document);

        if ($diff !== null && $diff->count === 0) {
            throw RollbackRefused::noChange($version);
        }

        $summary = 'Rolled back to version ' . $version;

        $published = self::publishVersion(new PublishArgs(
            data: $document,
            by: $by,
            source: 'restore:' . $version,
            summary: $summary,
            changeCount: $diff?->count ?? 0,
            // The version being undone has to still be the live one. If
            // somebody published while the owner was deciding, this refuses
            // rather than quietly undoing a publish nobody asked about - which
            // it can only do when the caller says which version that was.
            expectedBase: $expectedLive ?? $live?->version,
            liftHold: false,
        ));

        // Written here rather than left to the caller, because "who put the
        // old prices back, and when" is the question this button exists to
        // make answerable a year later, and a caller that forgets the line is
        // a caller that silently removes the answer. `Audit::log()` never
        // throws: the publish has already happened and a full diary must not
        // undo it.
        Audit::log($by, 'rates_rolled_back', [
            'fromVersion'  => $version,
            'version'      => $published->version,
            'summary'      => $summary,
            'changeCount'  => $diff?->count ?? 0,
            // So the screen can say "the prices are still held" instead of the
            // owner finding out from a customer.
            'pricesOnHold' => Hold::get()->on,
        ], $request);

        return $published;
    }

    /**
     * An old version's rates, company details, words, promo popup and customer
     * reviews, carried into the present: `live` and the remembered import
     * layouts come from `$current`, everything else from `$old`.
     *
     * The two flags are about the site as it stands - whether the rates are
     * being shown as real and which spreadsheet layouts the admin has taught
     * the importer - and neither is a price. A restore or a roll-back that
     * reset them would drop the site into sample mode or forget a layout, for
     * no reason anyone asked for.
     *
     * Everything else is part of the version being restored and so comes from
     * `$old` - including the promo block and the reviews. Each has to be named
     * explicitly: a `new SiteData(...)` that leaves one out gives it the
     * constructor default (a disabled popup, an empty review list), which would
     * silently wipe the owner's offer or testimonials on every roll-back.
     */
    private static function rebuild(SiteData $old, SiteData $current): SiteData
    {
        return new SiteData(
            card: $old->card,
            company: $old->company,
            content: $old->content,
            live: $current->live,
            importProfiles: $current->importProfiles,
            promo: $old->promo,
            reviews: $old->reviews,
        );
    }

    /* --------------------------------------------------------- publish */

    /**
     * Insert the next version atomically and re-base the draft onto it.
     *
     * Every publish path - admin, import, email auto-publish, seed, scripts -
     * comes through here, so the document is checked against the schema once
     * more on the way in.
     *
     * THE RAW DOCUMENT IS CHECKED FIRST, BEFORE THE NORMALISED ONE. They are
     * not the same question. `$args->data->toArray()` has been through
     * `SiteData::fromArray()`, which coerces: hand it `"services": "yes"` and
     * it stores `"services": []` and reports no problem at all, because by the
     * time the check runs there is nothing left to complain about. The version
     * that would go live is then a document nobody submitted. `$args->raw` is
     * what was actually sent, so the check reads the question; the normalised
     * check stays because it catches the caps that survive normalisation (an
     * over-long `content.phone2` is still over-long afterwards).
     */
    public static function publishVersion(PublishArgs $args): PublishedVersion
    {
        $doc = $args->data->toArray();

        $issue = $args->raw === null ? null : Schema::firstIssue($args->raw);
        $issue ??= Schema::firstIssue($doc);

        if ($issue !== null) {
            throw new \InvalidArgumentException('refusing to publish an invalid document: ' . $issue);
        }

        $json = Db::json($doc);
        $by   = Str::limit($args->by, self::BY_MAX);
        $at   = Clock::sql();

        self::lock();

        try {
            $published = Db::transaction(static function () use ($args, $json, $by, $at): PublishedVersion {
                // FOR UPDATE (2026-10-26, MariaDB 11.8): a plain read here
                // opened a snapshot, and the draft row a save committed after
                // it was then refused with 1020 at the FOR UPDATE below. A
                // locking read opens none, so this transaction reads only
                // current rows and a racing save surfaces as DraftConflict.
                $liveVersion = (int) (Db::value('SELECT MAX(version) FROM versions FOR UPDATE') ?? 0);

                if ($args->expectedBase !== null && $args->expectedBase !== $liveVersion) {
                    throw new PublishConflict($liveVersion);
                }

                // THE DRAFT HAS TO STILL BE THE ONE THE REVIEW CARD LISTED.
                // The caller compared the fingerprint before getting here, but
                // a save can land between that comparison and this lock, so it
                // is asked again with the row held - the same FOR UPDATE
                // `saveDraft()` takes, so the two cannot both win. Thrown
                // inside the transaction: nothing has been written.
                if ($args->expectedRevision !== null) {
                    $row = Db::one(
                        'SELECT base_version, data, updated_at, updated_by FROM draft WHERE id = ? FOR UPDATE',
                        [self::DRAFT_ID]
                    );

                    if ($row === null) {
                        throw new \RuntimeException('The draft was discarded while it was being published.');
                    }

                    $current = self::toDraft($row);

                    if (!hash_equals($current->revision, $args->expectedRevision)) {
                        throw new DraftConflict($current);
                    }
                }

                $version = $liveVersion + 1;

                Db::exec(
                    'INSERT INTO versions (version, published_at, published_by, source, summary, change_count, data)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $version,
                        $at,
                        $by,
                        Str::limit($args->source, self::SOURCE_MAX),
                        $args->summary,
                        $args->changeCount,
                        $json,
                    ]
                );

                // THE DRAFT BECOMES THE PUBLISHED DOCUMENT, whatever it held.
                // On an admin publish that is the same document; on a
                // roll-back it is not, and any unpublished edits in the editor
                // are replaced. That is still the right shape - a draft left
                // holding the bad prices would put them back on the next
                // publish - but it is not "nothing is lost", so the roll-back
                // question counts those edits and says so before the owner
                // confirms (`Http\Admin\Notice::rollbackQuestion()`).
                Db::exec(
                    'INSERT INTO draft (id, base_version, data, updated_at, updated_by) VALUES (:id, :v, :d, :at, :by)'
                    . ' ON DUPLICATE KEY UPDATE base_version = :v2, data = :d2, updated_at = :at2, updated_by = :by2',
                    [
                        ':id'  => self::DRAFT_ID,
                        ':v'   => $version,
                        ':d'   => $json,
                        ':at'  => $at,
                        ':by'  => $by,
                        ':v2'  => $version,
                        ':d2'  => $json,
                        ':at2' => $at,
                        ':by2' => $by,
                    ]
                );

                // The draft now IS the published document, so a note about
                // how it was once carried onto an older version is history.
                self::clearRebaseState();

                return new PublishedVersion(Migrate::migrate(json_decode($json, true) ?: []), $version, self::iso($at));
            });
        } finally {
            self::unlock();
        }

        // Only after the commit: a cache busted while the transaction is still
        // open would be refilled with the old document by any request that
        // arrives in between.
        Live::bust();

        if ($args->liftHold) {
            Hold::set(false, $by);
        }

        return $published;
    }

    /* ----------------------------------------------------------- names */

    /**
     * `id => name` for the destinations or services in `$ids`: the live
     * document's name, or for an id it no longer carries, the name from the most
     * recent version that still had it.
     *
     * Shipments and leads store only ids, so without this a country removed from
     * the rates would print as "gb" on every record booked under it - the
     * customer's WhatsApp update included. Ids no version ever knew are left
     * out; callers fall back to the id itself.
     *
     * @param  'destinations'|'services' $field
     * @param  iterable<string>          $ids
     * @return array<string,string>
     */
    public static function namesFor(string $field, ?SiteData $live, iterable $ids): array
    {
        if ($field !== 'destinations' && $field !== 'services') {
            throw new \InvalidArgumentException('namesFor expects "destinations" or "services".');
        }

        $names = [];
        if ($live !== null) {
            $items = $field === 'destinations' ? $live->card->destinations : $live->card->services;
            foreach ($items as $item) {
                $names[$item->id] = $item->name;
            }
        }

        $missing = [];
        foreach ($ids as $id) {
            if ($id !== '' && !isset($names[$id]) && !isset($missing[$id])) {
                $missing[$id] = true;
            }
        }

        foreach (array_keys($missing) as $id) {
            $name = self::retiredName($field, (string) $id);
            if ($name !== null) {
                $names[(string) $id] = $name;
            }
        }

        return $names;
    }

    /**
     * The name an id last had, from the most recent version that still carried
     * it; null when no version ever did.
     *
     * The JSON path is chosen from two literals, never built from input.
     */
    private static function retiredName(string $field, string $id): ?string
    {
        $path = $field === 'destinations' ? '$.destinations' : '$.services';

        $row = Db::one(
            'SELECT data FROM versions WHERE JSON_CONTAINS(JSON_EXTRACT(data, ?), ?, \'$\')'
            . ' ORDER BY version DESC LIMIT 1',
            [$path, Db::json(['id' => $id])]
        );

        if ($row === null) {
            return null;
        }

        $site  = Migrate::migrate(Db::unjson($row['data']));
        $items = $field === 'destinations' ? $site->card->destinations : $site->card->services;

        foreach ($items as $item) {
            if ($item->id === $id) {
                return $item->name;
            }
        }

        return null;
    }

    /* ------------------------------------------------------- internals */

    /** @param array<string,mixed> $row */
    private static function toPublished(array $row): PublishedVersion
    {
        return new PublishedVersion(
            Migrate::migrate(Db::unjson($row['data'])),
            (int) $row['version'],
            self::iso($row['published_at']),
        );
    }

    /** @param array<string,mixed> $row */
    private static function toDraft(array $row): Draft
    {
        return Draft::of(
            (int) $row['base_version'],
            Migrate::migrate(Db::unjson($row['data'])),
            self::iso($row['updated_at']),
            (string) $row['updated_by'],
        );
    }

    /** @return array<string,mixed>|null */
    private static function draftRow(): ?array
    {
        return Db::one(
            'SELECT base_version, data, updated_at, updated_by FROM draft WHERE id = ?',
            [self::DRAFT_ID]
        );
    }

    /**
     * Take the publisher's turn. A refusal is an error, not a warning: two
     * publishes that both believed they had the lock would be worse than one
     * publish that failed and can be retried.
     */
    /** The lock for a job that may simply skip this time; false when somebody else holds it. */
    private static function tryLock(int $wait): bool
    {
        return (int) Db::value('SELECT GET_LOCK(?, ?)', [self::PUBLISH_LOCK, max(0, $wait)]) === 1;
    }

    private static function lock(): void
    {
        $got = Db::value('SELECT GET_LOCK(?, ?)', [self::PUBLISH_LOCK, self::LOCK_TIMEOUT]);

        if ((int) $got !== 1) {
            throw new \RuntimeException('Another publish is in progress; please try again in a moment.');
        }
    }

    private static function unlock(): void
    {
        Db::value('SELECT RELEASE_LOCK(?)', [self::PUBLISH_LOCK]);
    }

    /** A MySQL DATETIME (stored UTC) as the ISO string the TypeScript prints. */
    private static function iso(mixed $sql): string
    {
        $at = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            substr((string) $sql, 0, 19),
            new \DateTimeZone('UTC')
        );

        return $at === false ? '' : $at->format('Y-m-d\TH:i:s.v\Z');
    }
}
