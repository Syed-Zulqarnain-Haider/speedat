<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Request;

/**
 * What the screen says after a post, carried across the redirect as a CODE.
 *
 * WHY A CODE IN THE QUERY STRING AND NOT A FLASH. Every admin post here is
 * post/redirect/get: the write happens, the browser is 303'd to a GET, and the
 * page is rendered from the database. That is what makes a reload safe - F5 on
 * "Version 12 is live" must not publish version 13 - but it leaves the outcome
 * with nowhere to live. `Http\Flash` carries a NAME and no payload (by design:
 * "a cookie is not a place to keep a customer's message"), and this application
 * has no session-scoped scratch area to put a sentence in.
 *
 * So the redirect carries a code and this class owns the sentences. Three
 * properties follow, and all three matter:
 *
 *   1. NOTHING A PERSON TYPED EVER REACHES A URL. Every value below is an
 *      integer or one word from a fixed list; a country's name, an error from
 *      the database and an admin's address cannot be in there, because there is
 *      no code that carries text.
 *   2. AN UNKNOWN CODE IS SILENCE. `?published=nonsense` prints nothing at all
 *      rather than a made-up sentence, so a hand-edited URL cannot put words
 *      into the owner's screen.
 *   3. THE SENTENCES ARE IN ONE FILE, so "3 countries sorted" and "Sorted
 *      A-Z" cannot both exist in two templates.
 *
 * The codes are read straight off `$request->query()`, which trims and caps
 * them, and every integer goes through the same narrow parse.
 */
final class Notice
{
    /**
     * The codes that carry a whole number.
     *
     * `base` is not a message. It is the published version the editor was
     * OPENED at, carried across every post/redirect/get so that the next render
     * can put it back into the save and publish forms - the link that makes the
     * stale-publish refusal reachable at all. It travels through here because
     * this class is the only thing in the admin allowed to add a parameter to a
     * redirect, and because the filter below is exactly the one it needs: a
     * whole number under a name this file knows, and nothing else.
     *
     * `rollback` is the version a roll-back has been asked for and not yet
     * confirmed; `rolledback` is the version number a finished roll-back
     * published.
     */
    private const NUMERIC = [
        'published', 'restored', 'conflict', 'bulk', 'kept', 'lead',
        'base', 'rollback', 'rolledback',
    ];

    /** The codes that are simply present or absent. */
    private const FLAGS = [
        'saved', 'added', 'removed', 'undone', 'sorted', 'filled', 'discarded', 'resumed', 'nochange',
        'enrol', 'created', 'totpon', 'totpoff', 'held', 'resumedprices', 'updated', 'event',
        // `replaced`: a Restore landed on top of unpublished edits, which it
        // threw away - the toast has to say so. `noname`: a country's name box
        // arrived empty, and the stored name was kept.
        'replaced', 'noname',
    ];

    /** `err=<word>` and the sentence each word means. */
    private const ERRORS = [
        'code'       => 'That code was not right, or it has already been used. Try the next one.',
        'expired'    => 'That took too long. Sign in again.',
        'nocode'     => 'Enter the 6-digit code from your authenticator app.',
        'totp_on'    => 'Two-factor sign-in is already on for this account.',
        'totp_off'   => 'Two-factor sign-in is not on for this account.',
        'noenrol'    => 'Start the set-up again: there is no pending authenticator to confirm.',
        'novalue'    => 'Nothing was changed: enter a value first.',
        'noservice'  => 'Nothing was changed: choose at least one service.',
        'nodest'     => 'That country is not in the editor.',
        'nolead'     => 'That enquiry is not in the inbox any more.',
        'noshipment' => 'That shipment does not exist.',
        'badstatus'  => 'That is not a status a shipment can move to from where it is now.',
        'toolong'    => 'Something you typed is longer than we can store. Shorten it and try again.',
        'nothing'    => 'Nothing was saved: the form arrived empty.',
        // Nothing was written, deliberately: see DraftForm::wasTruncated().
        // THE SENTENCE NAMES THE FIX, in the words of the buttons that are on
        // the screen. A refusal that only says "too big" leaves the owner
        // pressing the same button again; the editor now saves one country at
        // a time, so the answer is on the row he is looking at.
        'toobig'     => 'That was more than the server will accept in one go, so nothing was saved. '
            . 'Save this country on its own with the Save button on its row, or make fewer changes at once '
            . '(Settings and Website pages have their own Save). '
            . 'If it keeps happening, lower the cargo threshold in Settings.',
        // The shape check that runs before the write, not at publish time.
        // No field name in the sentence: the codes in this class are words
        // from a fixed list and never text, and the field path is in the log.
        'badshape'   => 'Something in that form was not a value we can store - most often a price far larger '
            . 'than any real one - so nothing was saved. Check what you just typed and save again.',
        'publish'    => 'That could not be published, and nothing was written. Try again in a moment.',
        // There is ONE draft row for the whole office. This is what the second
        // person to press Save is told instead of quietly writing over the
        // first. Nothing was saved: Repo::saveDraft() refuses inside the
        // transaction that would have done the writing.
        'draftmoved' => 'Somebody else changed the draft while this page was open, so nothing was saved. '
            . 'Reload the page to see their version, then make your change again.',
        // The same refusal when the "somebody" was the automatic catch-up
        // (`Repo::catchUpDraft()`): the owner must not be told a colleague did it.
        'draftrebased' => 'The draft was brought up to date with the live website while this page was open, so nothing was saved. '
            . 'Reload the page to see it, then make your change again.',
        // The draft changed between drawing the review card and pressing
        // Publish - a colleague's autosave, most often. Nothing went live:
        // publishing what the card did not list is the one thing it must
        // never do. The card is redrawn under this sentence.
        'reviewmoved' => 'The draft changed after this review was drawn, so nothing was published. '
            . 'Read the updated list below and publish again.',
        // The live version moved between asking the roll-back question and
        // confirming it. The question card is drawn again under this, against
        // the version that is live now.
        'rollmoved'  => 'Another version was published while you were deciding, so nothing was rolled back. '
            . 'Check the history, then answer the question again if you still need to.',
        // The three ways Repo::rollbackTo() refuses. Each is a fact about the
        // version asked for, never about the person asking.
        'norollback' => 'That version was never published, so there is nothing to go back to.',
        'rollsame'   => 'That version is already the live one, so rolling back would change nothing.',
        'rollstale'  => 'That version no longer passes the checks a publish has to pass, so it was not put back. '
            . 'Restore it into the editor, fix what it is missing, and publish it.',
    ];

    /** `bulkerr=<word>`: the two refusals Bulk adjust has. */
    private const BULK_ERRORS = [
        'services' => 'Choose at least one service and one price field.',
        'value'    => 'Enter a value to add or a percentage to change by.',
    ];

    /* ------------------------------------------------------------ reading */

    /** The one-off line admin.js shows as a toast, or "". */
    public static function toast(App $app): string
    {
        $q = $app->request;

        $published = self::number($q, 'published');
        if ($published > 0) {
            return 'Version ' . $published . ' is live'
                . (self::flag($q, 'resumed') ? ' and prices are showing again' : '');
        }

        $restored = self::number($q, 'restored');
        if ($restored > 0) {
            // A restore REPLACES the draft. When there were unpublished edits
            // in it, "is back in the editor" alone read as if they were still
            // there alongside it.
            return 'Version ' . $restored . ' is back in the editor'
                . (self::flag($q, 'replaced') ? ' - it replaced your earlier unpublished edits' : '');
        }

        $rolledBack = self::number($q, 'rolledback');
        if ($rolledBack > 0) {
            return 'Rolled back - version ' . $rolledBack . ' is live';
        }

        return match (true) {
            self::flag($q, 'discarded')     => 'Draft discarded',
            self::flag($q, 'added')         => 'Country added',
            self::flag($q, 'removed')       => 'Country removed',
            self::flag($q, 'undone')        => 'Row restored to the live version',
            self::flag($q, 'sorted')        => "Countries sorted A\u{2013}Z",
            self::flag($q, 'filled')        => 'Quick fill applied',
            self::flag($q, 'saved')         => 'Draft saved',
            self::flag($q, 'held')          => 'Prices are off the website',
            self::flag($q, 'resumedprices') => 'Prices are showing again',
            self::flag($q, 'totpon')        => 'Two-factor sign-in is on',
            self::flag($q, 'totpoff')       => 'Two-factor sign-in is off',
            self::flag($q, 'updated')       => 'Saved',
            self::flag($q, 'event')         => 'Update added',
            self::flag($q, 'created')       => 'Shipment created',
            default                         => '',
        };
    }

    /**
     * The line under the table after a publish. It is a full sentence rather
     * than the toast's fragment, because it stays on the page.
     */
    public static function published(App $app): string
    {
        $version = self::number($app->request, 'published');

        if ($version <= 0) {
            return '';
        }

        return 'Version ' . $version . ' is live. Customers see these prices now'
            . (self::flag($app->request, 'resumed') ? ', and prices are showing again.' : '.');
    }

    /**
     * What the review card says under its buttons: a publish that was refused
     * because somebody else got there first, or because nothing had changed.
     *
     * The conflict wording is the TypeScript's, character for character
     * (src/app/admin/actions.ts, `onError`), so the two halves of this product
     * tell the owner the same thing.
     */
    public static function reviewMessage(App $app): string
    {
        $live = self::number($app->request, 'conflict');

        if ($live > 0) {
            return 'Version ' . $live . ' was published meanwhile. Reload to see it, then apply your changes again.';
        }

        return self::flag($app->request, 'nochange') ? 'There is nothing to publish.' : '';
    }

    /**
     * WHICH VERSION A ROLL-BACK HAS BEEN ASKED FOR AND NOT YET CONFIRMED, or 0.
     *
     * Rolling back is one press and the whole website is serving different
     * prices, so the button does not do it: it asks. The POST without
     * `confirm=1` writes nothing and redirects here, and the screen draws its
     * confirmation from this number. A hand-typed `?rollback=9999` therefore
     * costs a question about a version that does not exist and nothing else -
     * the work happens on the confirming POST, behind CSRF and the owner check.
     */
    public static function rollbackAsked(App $app): int
    {
        return self::number($app->request, 'rollback');
    }

    /**
     * The confirmation sentence itself, so the words live here with every other
     * sentence the admin says rather than in a template.
     *
     * `$draftChanges` is how many unpublished changes the editor is holding.
     * A roll-back re-bases the draft onto what it publishes
     * (`Repo::publishVersion()`), so those edits are REPLACED - and the
     * sentence used to promise "nothing is lost" over the top of them. With
     * edits in the draft that promise is only true of the published history,
     * so it says exactly that, and says what happens to the rest.
     */
    public static function rollbackQuestion(App $app, int $draftChanges = 0): string
    {
        $version = self::rollbackAsked($app);

        if ($version <= 0) {
            return '';
        }

        $question = 'Put version ' . $version . ' back on the website now? '
            . 'Customers see its prices immediately, and it is published as a new version, ';

        if ($draftChanges > 0) {
            return $question
                . 'so every published version is kept and it can be undone the same way. '
                . 'Your ' . $draftChanges . ' unpublished change' . ($draftChanges === 1 ? '' : 's')
                . ' in the editor will be replaced by version ' . $version . '. '
                . 'If prices are on hold they stay on hold.';
        }

        return $question
            . 'so nothing is lost and it can be undone the same way. '
            . 'If prices are on hold they stay on hold.';
    }

    /**
     * The line that stays on the page after a roll-back, as `published()`
     * does after a publish.
     *
     * A ROLL-BACK NEVER LIFTS THE PRICE HOLD, and this is where that is said.
     * Somebody put the hold on for a reason the old document knows nothing
     * about, so resuming prices as a side effect of an undo would be a second
     * decision taken on his behalf without a word - `Repo::rollbackTo()`
     * passes `liftHold: false` for exactly that reason. The consequence is
     * that "customers see them now" is FALSE while prices are held, and the
     * owner must not learn that from a customer.
     */
    public static function rolledBack(App $app, bool $holdOn = false): string
    {
        $version = self::number($app->request, 'rolledback');

        if ($version <= 0) {
            return '';
        }

        $line = 'Version ' . $version . ' is live: it carries the prices of the version that was rolled back to. ';

        return $line . ($holdOn
            ? 'Prices are still on hold, so customers see no prices until you resume them.'
            : 'Customers see them now.');
    }

    /** The notice above the table: an error code, or a count of prices left alone. */
    public static function error(App $app): string
    {
        $code = $app->request->query('err', '', 24);

        return self::ERRORS[$code] ?? '';
    }

    /**
     * One refusal's sentence by its code, for the handler that already knows
     * which refusal it is answering with.
     *
     * The redirect path reads the code out of the URL (above); the fetch path
     * has to put the sentence in the JSON itself, and both must be the same
     * words - so both come from `ERRORS` and neither writes its own.
     */
    public static function text(string $code): string
    {
        return self::ERRORS[$code] ?? '';
    }

    /** Bulk adjust's own message and its class: ['ok'|'warn', sentence]. @return array{0:string,1:string} */
    public static function bulk(App $app): array
    {
        $changed = self::number($app->request, 'bulk');

        if ($changed > 0) {
            return ['ok', $changed . ' price' . ($changed === 1 ? '' : 's') . ' changed in the editor. Review before publishing.'];
        }

        $code = $app->request->query('bulkerr', '', 24);

        if (isset(self::BULK_ERRORS[$code])) {
            return ['warn', self::BULK_ERRORS[$code]];
        }

        return ['', ''];
    }

    /**
     * How many price boxes the last save refused, as a sentence.
     *
     * A number box can arrive holding something that is not a number (a client
     * with no validation, a paste, JavaScript off). The parser keeps the value
     * that was already stored rather than writing NaN into the document, and
     * this is how the owner is told - because a price that silently did not save
     * is the worst outcome on this screen.
     */
    public static function kept(App $app): string
    {
        return self::keptText(self::number($app->request, 'kept'));
    }

    /**
     * The same sentence, from a count the caller is already holding.
     *
     * THE AUTOSAVE NEVER SEES THE URL the redirect path reads that number out
     * of. It has the count in its hand and answers JSON, so without this it
     * had nothing to say and said nothing: the owner with JavaScript on - the
     * ordinary case - was shown "Draft saved" over prices that did not save,
     * which is the outcome the count exists to prevent. Both paths come
     * through here for the same reason every other sentence in this class is
     * in one place: two descriptions of one refusal is one description too
     * many.
     */
    public static function keptText(int $kept): string
    {
        if ($kept <= 0) {
            return '';
        }

        // "1 box ... it was left exactly as it was. Check THEM and save again."
        // was the sentence until the autosave started printing it, where a
        // single refused box is the ordinary case rather than the rare one.
        return $kept . ' box' . ($kept === 1 ? '' : 'es') . ' did not hold a number, so ' . ($kept === 1 ? 'it was' : 'they were')
            . ' left exactly as ' . ($kept === 1 ? 'it was' : 'they were') . '. Check '
            . ($kept === 1 ? 'it' : 'them') . ' and save again.';
    }

    /**
     * A country saved with its name box empty.
     *
     * It used to be counted with the price boxes that did not hold a number,
     * so the owner who keyed a new country's prices before naming it was
     * told on every save that a number box had not saved - although every
     * price had - and went back to re-key correct numbers. The name is its
     * own sentence now, about the name.
     */
    public static function noname(App $app): string
    {
        return self::flag($app->request, 'noname') ? self::nonameText() : '';
    }

    /** The same sentence for the autosave, which never sees the URL. */
    public static function nonameText(): string
    {
        return 'A country in the table has no name, so its name was left as it was. Give it one and save again.';
    }

    public static function flag(Request $request, string $name): bool
    {
        if (!in_array($name, self::FLAGS, true)) {
            return false;
        }

        return $request->query($name, '', 12) === '1';
    }

    public static function number(Request $request, string $name): int
    {
        if (!in_array($name, self::NUMERIC, true)) {
            return 0;
        }

        $raw = $request->query($name, '', 12);

        return preg_match('/^\d{1,9}$/', $raw) === 1 ? (int) $raw : 0;
    }

    /* ------------------------------------------------------------ writing */

    /**
     * A redirect target with codes on it.
     *
     * Only known keys survive, and a value is either a whole number or one of
     * the words this class recognises - so no caller can put a sentence, a
     * name, or a newline into a `Location` header through here. The path is
     * whatever the route constants say and is never built from a request.
     *
     * @param array<string,int|string|bool> $codes
     */
    public static function url(string $path, array $codes = []): string
    {
        $pairs = [];

        foreach ($codes as $key => $value) {
            if ($value === false || $value === '' || $value === 0) {
                continue;
            }

            if (in_array($key, self::NUMERIC, true) && is_int($value) && $value > 0) {
                $pairs[$key] = (string) $value;
                continue;
            }

            if (in_array($key, self::FLAGS, true) && ($value === true || $value === 1 || $value === '1')) {
                $pairs[$key] = '1';
                continue;
            }

            if ($key === 'err' && is_string($value) && isset(self::ERRORS[$value])) {
                $pairs[$key] = $value;
                continue;
            }

            if ($key === 'bulkerr' && is_string($value) && isset(self::BULK_ERRORS[$value])) {
                $pairs[$key] = $value;
            }
        }

        return $pairs === [] ? $path : $path . '?' . http_build_query($pairs);
    }
}
