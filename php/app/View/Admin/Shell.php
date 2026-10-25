<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The admin frame: who is signed in, which page they are on, and the five
 * links down the side. Port of `AdminShell.tsx` and `AdminNav.tsx`.
 *
 * Both navigations - the sidebar from 1024px and the scrolling row on a phone
 * - are always in the markup; CSS shows one. Nothing branches on the client,
 * so the HTML the server sends is final and a phone with no JavaScript still
 * has every link.
 *
 * The role is carried here because the templates ask it exactly one question:
 * may this person publish? Hiding a control is never the control - the server
 * re-checks the role on every action - but showing an editor a Publish button
 * that will be refused is a lie the screen tells.
 */
final class Shell
{
    public const OWNER = 'owner';

    /** @param array<string,int> $badges e.g. ['inbox' => 3, 'shipments' => 1] */
    public function __construct(
        public readonly string $companyName,
        public readonly string $userEmail,
        public readonly string $userRole,
        /** Which of the five links is the current page: rates|inbox|shipments|analytics|security. */
        public readonly string $current = 'rates',
        public readonly array $badges = [],
        /** The sidebar shows the editor's section sub-list only on the editor itself. */
        public readonly bool $showSections = false,
    ) {
    }

    /** Publish, resume prices and the other owner-only actions. */
    public function isOwner(): bool
    {
        return $this->userRole === self::OWNER;
    }

    /**
     * The nav, resolved: label, href, whether it is the current page and the
     * badge count (0 means no badge, as in the Next.js nav).
     *
     * @return list<array{key:string,label:string,href:string,active:bool,count:int}>
     */
    public function links(): array
    {
        // FIVE LINKS NOW, WITH ANALYTICS BEFORE SECURITY. Rates, Inbox and
        // Shipments are the day's work in the order it happens; Analytics is
        // what the owner opens on top of it; Security is the one nobody visits
        // twice a week, so it stays last. Analytics carries no badge - a number
        // beside a link means something is waiting there, and nothing ever is:
        // the page only reads.
        $links = [
            ['rates', 'Rates', Routes::RATES],
            ['inbox', 'Inbox', Routes::INBOX],
            ['shipments', 'Shipments', Routes::SHIPMENTS],
            ['analytics', 'Analytics', Routes::ANALYTICS],
            ['security', 'Security', Routes::SECURITY],
        ];

        $out = [];
        foreach ($links as [$key, $label, $href]) {
            $count = $this->badges[$key] ?? 0;
            $out[] = [
                'key'    => $key,
                'label'  => $label,
                'href'   => $href,
                'active' => $key === $this->current,
                'count'  => $count > 0 ? $count : 0,
            ];
        }

        return $out;
    }

    /**
     * The editor's sections, for the sidebar sub-list. Empty on every page
     * but the editor, which is what keeps the sub-list from appearing under
     * "Rates" while the owner is reading the inbox.
     *
     * @return list<array{id:string,label:string,anchor:string,no:string}>
     */
    public function sections(): array
    {
        return $this->showSections ? Sections::all() : [];
    }
}
