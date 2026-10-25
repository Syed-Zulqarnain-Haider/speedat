<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Format;

/**
 * The rates editor, whole. Port of AdminEditor.tsx with the browser's job
 * moved to the server: the draft lives in MySQL, the diff and the validation
 * decide what may be published, and the page is rendered from what the server
 * already knows.
 *
 * SIX SECTIONS, NOT SEVEN. "Import from Excel" was the second one; the owner
 * removed the feature on 2026-09-27, so there is no section, no dashboard
 * tile counting sheets that are waiting, and no notice telling the owner to
 * import anything. What is left is what he actually does: key the prices into
 * the boxes, review, publish.
 */
final class RatesPage
{
    /** A live version older than this many days is called out in the header and the tile. */
    private const STALE_DAYS = 7;

    /**
     * @param list<DestinationRow> $rows
     * @param list<VersionRow>     $versions
     */
    public function __construct(
        public readonly Site $draft,
        public readonly Site $live,
        public readonly array $rows,
        public readonly array $versions,
        public readonly HoldPanel $hold,
        public readonly BulkForm $bulk,
        public readonly TestPricePanel $test,
        /** How many changes the server counted between the draft and the live version. */
        public readonly int $changeCount,
        /** Whose edits are in the draft, and when it was last stored (ISO 8601). */
        public readonly string $draftUpdatedBy,
        public readonly string $draftUpdatedAt,
        public readonly string $viewerEmail,
        /** Publishing and resuming prices are the owner's. */
        public readonly bool $canPublish,
        /** Who published the live version, "" when the history does not say. */
        public readonly string $livePublishedBy = '',
        /** Whole days since the live version was published. */
        public readonly int $liveAgeDays = 0,
        /** The search box's current value; the server filtered the rows with it. */
        public readonly string $filter = '',
        /**
         * The draft as CSV, for the Copy button. It is the same text the
         * download gives, built once here rather than twice in two places.
         */
        public readonly string $csv = '',
        /** Rendered under the table when the owner pressed "Review and publish". */
        public readonly ?Review $review = null,
        /** A one-off line under the table: what the last post did, or refused to do. */
        public readonly string $publishedNotice = '',
        /**
         * Its tone - `ok`, `warn` or `err`, the three classes admin.css
         * paints. It exists because the template printed every one of these
         * as `notice ok`, so a refusal that saved nothing arrived in the same
         * green box as "version 12 is live".
         */
        public readonly string $publishedNoticeKind = 'ok',
        /** Handed to admin.js as a toast, e.g. "Version 12 is live". */
        public readonly string $toast = '',
        /** The whole editor is read-only for someone who may not edit at all. */
        public readonly bool $readOnly = false,
        /**
         * `Domain\SiteDoc\Draft::editedBy()` - "edited by office@speedat.pk at
         * 28 Sept 2026, 14:05". There is ONE draft row for the whole office,
         * so the editor says whose work is on the screen before anybody types
         * over it. "" when the row was written by no person.
         */
        public readonly string $draftEditedBy = '',
        /**
         * The last save was REFUSED because the draft had moved underneath
         * this screen. Nothing was written; `draftEditedBy` above names who
         * has it now, and the notice offers a reload.
         */
        public readonly bool $draftMoved = false,
        /** "Roll back to version N?" - the question, when one has been asked. */
        public readonly ?RollbackAsk $rollback = null,
    ) {
    }

    /** The eyebrow/heading pair at the top of the page. */
    public function eyebrow(): string
    {
        return Sections::no('rates') . " - Rates";
    }

    /** live | sample | hold - which pill sits beside the heading. */
    public function pricesPill(): string
    {
        if ($this->hold->on) {
            return 'hold';
        }

        return $this->live->live ? 'live' : 'sample';
    }

    /** "On hold" / "Live" / "Sample" - the Prices tile's big word. */
    public function pricesState(): string
    {
        return match ($this->pricesPill()) {
            'hold'  => 'On hold',
            'live'  => 'Live',
            default => 'Sample',
        };
    }

    public function stale(): bool
    {
        return $this->liveAgeDays >= self::STALE_DAYS;
    }

    /** "today", "yesterday", "9 days ago" - how the Live version tile reads. */
    public function ageText(): string
    {
        return match (true) {
            $this->liveAgeDays <= 0 => 'today',
            $this->liveAgeDays === 1 => 'yesterday',
            default => $this->liveAgeDays . ' days ago',
        };
    }

    /** "Version 7 - published 23 Sept 2026, 14:05 - by boss@speedat.pk". */
    public function liveMeta(): string
    {
        $text = 'Version ' . $this->live->version;
        $when = Format::fmtDateTime($this->live->publishedAt);
        if ($when !== '') {
            $text .= " \u{b7} published " . $when;
        }
        if ($this->livePublishedBy !== '') {
            $text .= " \u{b7} by " . $this->livePublishedBy;
        }

        return $text;
    }

    /**
     * The dashboard tiles - the loudest type on the page, so they answer the
     * question the owner actually arrives with: IS WHAT I TYPED ON THE WEBSITE
     * YET?
     *
     * There used to be three, and two of them said the same fact twice. "Live
     * version 84 / published today" and "Prices / Live / customers see version
     * 84" both rendered at 42.5px, neither mentioned that there was
     * unpublished work, and the only thing that did was a 14px pill beside the
     * heading - one third the type size of the numbers that told him nothing.
     * "Prices: Live" was worse than idle: it means "not on hold", but to a
     * courier operator with a price change pending it reads as "my prices are
     * published".
     *
     * So the version tile is gone - the version, its date and who published it
     * are already in the meta line beside the heading - and the hold state has
     * moved into the hold block, beside the button that changes it. What is
     * left is the two things on this page waiting for the owner to act: the
     * draft and the inbox.
     *
     * @return list<array{eyebrow:string,value:string,note:string,tone:string,href:string}>
     */
    public function tiles(int $newLeads = 0): array
    {
        $waiting = $this->changeCount > 0;

        return [
            [
                'eyebrow' => $waiting ? 'Unpublished' : 'Nothing waiting',
                'value'   => $waiting ? (string) $this->changeCount : 'None',
                'note'    => $waiting
                    ? ($this->hold->on
                        ? 'customers see no prices (on hold)'
                        : 'customers still see version ' . $this->live->version)
                    : 'the editor matches version ' . $this->live->version,
                'tone'    => $waiting ? 'tone-warn' : 'tone-ok',
                'href'    => '',
            ],
            [
                'eyebrow' => 'New leads',
                'value'   => (string) $newLeads,
                'note'    => 'in the inbox',
                'tone'    => $newLeads > 0 ? 'tone-hot' : '',
                'href'    => Routes::INBOX,
            ],
        ];
    }

    /**
     * The notices above the table, in the order the React editor showed them.
     *
     * @return list<array{kind:string,text:string}>
     */
    public function notices(): array
    {
        $out = [];

        if (!$this->canPublish) {
            $out[] = ['kind' => 'info', 'text' => 'You can edit and stage changes; an owner publishes them.'];
        }

        if (!$this->live->live) {
            $out[] = [
                'kind' => 'warn',
                'text' => "The website is still showing sample rates. Replace them and publish; the sample notice disappears when you tick \u{201c}Rates are live\u{201d}.",
            ];
        }

        if ($this->stale() && $this->live->live) {
            // The React copy ended "...import them below." There is nothing to
            // import any more, so it says what the owner will actually do.
            $out[] = [
                'kind' => 'warn',
                'text' => 'Rates were last published ' . $this->liveAgeDays . ' days ago. If your carrier has sent new rates since, key them in below and publish.',
            ];
        }

        if (
            $this->draftUpdatedBy !== ''
            && $this->draftUpdatedBy !== 'system'
            && $this->draftUpdatedBy !== $this->viewerEmail
            && $this->changeCount > 0
        ) {
            $when = Format::fmtDateTime($this->draftUpdatedAt);
            $out[] = [
                'kind' => 'info',
                'text' => 'This draft was last edited by ' . $this->draftUpdatedBy . ($when === '' ? '.' : ' on ' . $when . '.'),
            ];
        }

        return $out;
    }

    /** "3 unpublished changes" / "No unpublished changes" - the publish bar's headline. */
    public function changeSentence(): string
    {
        return $this->changeCount > 0
            ? $this->changeCount . ' unpublished change' . ($this->changeCount === 1 ? '' : 's')
            : 'No unpublished changes';
    }

    /**
     * "Discard all 3 changes?" - what the second press of Discard has to say.
     *
     * The button used to read "Discard" and confirm with "Confirm discard",
     * eight pixels from "Review and publish", in a bar fixed over the foot of
     * a page that had just said "each country saves on its own". It drops the
     * WHOLE draft, so the confirm names the scope and the count. admin.js
     * rewrites it after an autosave from the same count this reads; keep the
     * two sentences identical.
     */
    public function discardConfirm(): string
    {
        return 'Discard all ' . $this->changeCount . ' change' . ($this->changeCount === 1 ? '' : 's') . '?';
    }

    /** What the publish bar says customers can see right now, before the save state. */
    public function audienceSentence(): string
    {
        if ($this->changeCount === 0) {
            return '';
        }

        return $this->hold->on
            ? 'Customers see no prices (on hold). '
            : 'Customers still see version ' . $this->live->version . '. ';
    }

    /** "Draft saved 23 Sept 2026, 14:05" - and what admin.js replaces while typing. */
    public function saveState(): string
    {
        $when = Format::fmtDateTime($this->draftUpdatedAt);

        return $when === '' ? 'Draft not saved yet' : 'Draft saved ' . $when;
    }

    /** The value of the form's data-saved-at, which admin.js reprints after each autosave. */
    public function savedAt(): string
    {
        return Format::fmtDateTime($this->draftUpdatedAt);
    }

    /** The sentence under the table, which differs between the two pricing models. */
    public function tableNote(): string
    {
        if (!$this->draft->isGrid()) {
            return 'Prices are what the customer pays, before any tax you set in Settings. '
                . 'Leave First and Each blank to not offer a service for that destination. '
                . 'Changed rows are marked on the left; Undo restores the live version of that row.';
        }

        $kgs = $this->draft->gridWeights();
        $top = $kgs === [] ? 0 : $kgs[count($kgs) - 1];

        return 'Prices are what the customer pays, before any tax you set in Settings. '
            . "Open \u{201c}Prices\u{201d} on a country to enter its price for every kilogram from 1 kg to " . $top . ' kg '
            . '(the cargo threshold in Settings); a parcel is charged at the next whole kilogram up, and a blank kilogram is not offered at that weight. '
            . 'Changed rows are marked on the left; Undo restores the live version of that row.';
    }

    /** The paragraph under the "Prices by destination" heading. */
    public function tableLede(): string
    {
        if (!$this->draft->isGrid()) {
            return 'One row per country: the first slab, each additional step and working days. Changed rows carry an orange bar.';
        }

        $kgs = $this->draft->gridWeights();
        $top = $kgs === [] ? 0 : $kgs[count($kgs) - 1];

        return 'One row per country: working days and a price for every kilogram up to ' . $top . ' kg. Changed rows carry an orange bar.';
    }

    public function firstHeading(): string
    {
        return 'First ' . Money::numberToString($this->draft->settings()->firstKg) . ' kg';
    }

    public function stepHeading(): string
    {
        return 'Each +' . Money::numberToString($this->draft->settings()->stepKg) . ' kg';
    }

    /** "Prices 1-25 kg", printed on two lines so it clears the pinned actions column at 1366px. */
    public function gridHeadingTop(): string
    {
        return 'Prices';
    }

    public function gridHeadingBottom(): string
    {
        $kgs = $this->draft->gridWeights();

        return $kgs === [] ? '' : "1\u{2013}" . $kgs[count($kgs) - 1] . ' kg';
    }

    /**
     * Every country in the draft, for the two controls that need a list of
     * them: "copy this ladder to ..." and the one-weight column view.
     *
     * It is built from `$this->rows` and not from the document, so a row the
     * search box is hiding is still in the list - the owner is copying to a
     * country, not to a row he can currently see. A country with no name yet
     * is offered under a placeholder rather than as a blank line.
     *
     * @return list<array{id:string,name:string}>
     */
    public function countries(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[] = ['id' => $row->id, 'name' => $row->name === '' ? '(unnamed country)' : $row->name];
        }

        return $out;
    }

    /**
     * The kilograms the column view can show, one at a time. Empty in slab
     * mode, where there is no ladder to look down.
     *
     * @return list<int>
     */
    public function weights(): array
    {
        return $this->draft->isGrid() ? $this->draft->gridWeights() : [];
    }

    /** The weight the column view opens on: the one a rate change usually names. */
    public function defaultWeight(): int
    {
        $kgs = $this->weights();

        if ($kgs === []) {
            return 0;
        }

        // 10 kg if the ladder reaches it - the weight the owner is most often
        // handed a new number for - and otherwise the heaviest there is.
        return in_array(10, $kgs, true) ? 10 : $kgs[count($kgs) - 1];
    }

    /** How many columns the table has, so the fold row can span all of them. */
    public function columnCount(): int
    {
        $services = count($this->draft->services());

        // grid: name + on-site + (days) per service + the Prices column + actions
        // slab: name + on-site + (first, each, days) per service + actions
        return $this->draft->isGrid()
            ? 2 + $services + 2
            : 2 + $services * 3 + 1;
    }
}
