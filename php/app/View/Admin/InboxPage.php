<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The inbox: booked quotes and contact messages, worked left to right,
 * new -> contacted -> booked. Port of app/admin/inbox/page.tsx.
 */
final class InboxPage
{
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
        /** The search box's value, for the client-side list filter. */
        public readonly string $filter = '',
    ) {
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
        $labels = ['new' => 'New', 'contacted' => 'Contacted', 'booked' => 'Booked', 'lost' => 'Lost'];

        $tabs  = [];
        $total = 0;
        foreach ($labels as $key => $label) {
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
}
