<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The inbox: booked quotes and contact messages, worked left to right,
 * new -> contacted -> booked. Port of app/admin/inbox/page.tsx.
 */
final class InboxPage
{
    /** The tab labels, and the word "moved to ..." uses after a save. */
    private const LABELS = ['new' => 'New', 'contacted' => 'Contacted', 'booked' => 'Booked', 'lost' => 'Lost'];

    /**
     * @param list<LeadCard>     $leads
     * @param array<string,int>  $counts status => how many, as the tabs print them
     */
    public function __construct(
        public readonly array $leads,
        public readonly array $counts = [],
        /** Which tab is showing: one of the statuses, or "all". */
        public readonly string $status = 'new',
        /** False when outgoing mail is not configured; the page says so rather than losing messages quietly. */
        public readonly bool $alertsConfigured = true,
        /** The search box's value: the server searched with it, and admin.js filters with it. */
        public readonly string $filter = '',
        /**
         * How many leads the tab (or the search) holds in all, of which
         * `$leads` is one screen. Null means "what is on the screen is all of
         * it", which is what a caller that does not page gets.
         */
        public readonly ?int $total = null,
        /** How many newer leads come before this screen. */
        public readonly int $offset = 0,
        /** One screen's worth; the controller's page size. */
        public readonly int $perPage = 200,
        /** The one-off line admin.js shows after a save, "" for none. */
        public readonly string $toast = '',
        /** The refusal the last post came back with, "" for none. */
        public readonly string $error = '',
    ) {
    }

    /** "Contacted" for "contacted"; an unknown word comes back as it is. */
    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public function eyebrow(): string
    {
        return "02 - Inbox";
    }

    public function newCount(): int
    {
        return $this->counts['new'] ?? 0;
    }

    /**
     * The status tabs and their counts. "All" is the sum of the four, not a
     * fifth stored state.
     *
     * @return list<array{key:string,label:string,count:int,href:string,active:bool}>
     */
    public function tabs(): array
    {
        $tabs  = [];
        $total = 0;
        foreach (self::LABELS as $key => $label) {
            $count  = $this->counts[$key] ?? 0;
            $total += $count;
            $tabs[] = [
                'key'    => $key,
                'label'  => $label,
                'count'  => $count,
                'href'   => Routes::inbox($key),
                'active' => $this->status === $key,
            ];
        }

        $tabs[] = [
            'key'    => 'all',
            'label'  => 'All',
            'count'  => $total,
            'href'   => Routes::inbox('all'),
            'active' => $this->status === 'all',
        ];

        return $tabs;
    }

    public function isEmpty(): bool
    {
        return $this->leads === [];
    }

    public function isSearch(): bool
    {
        return trim($this->filter) !== '';
    }

    /**
     * What the empty list says. A search that found nothing says so in the
     * words that were searched for, because "Nothing here." under a search box
     * reads as "this tab is empty" - which it may well not be.
     */
    public function emptyLine(): string
    {
        return $this->isSearch() ? 'No leads match "' . trim($this->filter) . '".' : 'Nothing here.';
    }

    /** The tab without the search: the "clear" link next to a search. */
    public function clearHref(): string
    {
        return Routes::inbox($this->status);
    }

    /** How many leads there are in all, on this screen and off it. */
    public function totalCount(): int
    {
        return $this->total ?? $this->offset + count($this->leads);
    }

    /**
     * "Showing 1-200 of 340" when the tab holds more than one screen, or ""
     * when everything is already on it - a line that says "showing 3 of 3"
     * is only noise.
     */
    public function rangeLine(): string
    {
        $total = $this->totalCount();
        $shown = count($this->leads);

        if ($shown === 0 || ($this->offset === 0 && $shown >= $total)) {
            return '';
        }

        $from = $this->offset + 1;
        $to   = $this->offset + $shown;

        return 'Showing ' . $from . '-' . $to . ' of ' . $total . ($this->offset === 0 ? ', newest first' : '');
    }

    /** The next screen of older leads, or "" when this screen reaches the oldest. */
    public function olderHref(): string
    {
        if ($this->leads === [] || $this->offset + count($this->leads) >= $this->totalCount()) {
            return '';
        }

        return $this->pageHref(intdiv($this->offset, max(1, $this->perPage)) + 2);
    }

    /** The screen of newer leads before this one, or "" on the first. */
    public function newerHref(): string
    {
        if ($this->offset <= 0) {
            return '';
        }

        return $this->pageHref(intdiv($this->offset, max(1, $this->perPage)));
    }

    /** Which screen this is, 1 for the newest; each card posts it back so a save returns here. */
    public function pageNo(): int
    {
        return intdiv($this->offset, max(1, $this->perPage)) + 1;
    }

    private function pageHref(int $pageNo): string
    {
        $href = Routes::inbox($this->status);

        if ($this->isSearch()) {
            $href .= '&q=' . rawurlencode(trim($this->filter));
        }

        return $pageNo > 1 ? $href . '&page=' . $pageNo : $href;
    }
}
