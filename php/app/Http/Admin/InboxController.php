<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Leads\Lead;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Leads\LeadStatus;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\Domain\SiteDoc\Live;
use Speedat\View\Admin\InboxPage;
use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\Routes;

/**
 * The inbox: booked quotes and contact messages, worked left to right.
 *
 * THE COUNTRY IS PRINTED AS THE NAME IT WAS BOOKED UNDER. A lead stores a
 * destination ID, and a country taken off the rate card still has leads pointing
 * at it - so the names come from `Shipments\NameLookup`, which reads the live
 * document first and then the most recent version that still carried the id.
 * Without it every lead for a retired country would read "gb".
 *
 * THE SEARCH BOX WORKS WITHOUT JAVASCRIPT. It is a GET form; admin.js hides rows
 * as the office types, and with scripting off the server searches here. Both
 * cut the search the same accent-free way (`Str::nameKey`), so "turkiye" finds
 * "Türkiye" whichever side is doing the work.
 *
 * THE SERVER SEARCHES THE WHOLE TAB, NOT THE SCREEN (fixed 2026-10-25). It used
 * to filter the 200 newest leads in PHP, so a customer from three months ago
 * was "Nothing here." on a tab whose badge said 340, and that empty state
 * replaced the search box, so the search could not even be corrected.
 * `LeadRepository::search()` now runs it in SQL over every row, the list pages
 * past its first 200 (`?page=`), and the box is always on the screen.
 *
 * SAVING IS A POST, A WRITE AND A REDIRECT. The React list patched the row in
 * place over a server action; here the card posts, the server validates, and the
 * page comes back - slower by one load, and worth it on the office laptop with
 * scripting blocked.
 *
 * AND IT COMES BACK TO WHERE IT WAS (fixed 2026-10-25). The redirect used to
 * land on the New tab with no search and no word on the screen: notes saved on
 * the Contacted tab, or under a search for "Ali", vanished with the card, and
 * read as notes lost. Each card now posts the tab, the search and the page it
 * was saved from, the redirect carries them back with the card's own anchor,
 * and the screen says "Saved" - or "Saved - moved to Contacted" when the
 * status change took the card off the tab being looked at.
 */
final class InboxController
{
    /** How many leads one screen holds. The repository's own ceiling is 500. */
    private const PAGE = 200;

    public function __construct(private readonly LeadRepository $leads = new LeadRepository())
    {
    }

    /** GET /admin/inbox */
    public function page(App $app, AdminUser $admin): Response
    {
        $status = $this->status($app->request->query('status', LeadStatus::NEW, 16));
        $filter = $app->request->query('q', '', 80);
        $pageNo = self::pageNo($app->request->query('page', '1', 6));
        $offset = ($pageNo - 1) * self::PAGE;

        $counts = $this->leads->counts()->toArray();

        if (trim($filter) === '') {
            $leads = $this->leads->list($status, self::PAGE, $offset);
            // The badge already counted the tab; asking again would be the same number.
            $total = $status === LeadStatus::ANY ? array_sum($counts) : (int) ($counts[$status] ?? 0);
        } else {
            $destIds = self::destinationsNamed($filter);
            $leads   = $this->leads->search($status, $filter, $destIds, self::PAGE, $offset);
            $total   = $this->leads->total($status, $filter, $destIds);
        }

        $names = new NameLookup();
        $cards = [];
        foreach ($leads as $lead) {
            $cards[] = $this->card($lead, $names);
        }

        $page = new InboxPage(
            leads: $cards,
            counts: $counts,
            status: $status,
            // A deployment that cannot email says so on the page rather than
            // losing messages quietly: they still land here.
            alertsConfigured: $app->mail()->configured(),
            filter: $filter,
            total: $total,
            offset: $offset,
            perPage: self::PAGE,
            toast: self::toast($app),
            error: Notice::error($app),
        );

        return $app->page('admin/inbox', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'inbox'),
            'theme' => Screen::theme($app),
        ]);
    }

    /** POST /admin/inbox/update - one card's status and notes. */
    public function update(App $app, AdminUser $admin): Response
    {
        $id     = $app->request->postInt('id');
        $status = $app->request->post('status', '', 16);
        $notes  = $app->request->post('notes', '', 4000);

        // Where the card was saved from, so the answer comes back there.
        $where = [
            'tab'  => $this->status($app->request->post('tab', LeadStatus::NEW, 16)),
            'q'    => $app->request->post('q', '', 80),
            'page' => self::pageNo($app->request->post('page', '1', 6)),
        ];

        if ($id < 1) {
            return $this->back($app, ['err' => 'nolead'], $where);
        }

        $before = $this->leads->find($id);

        if ($before === null) {
            return $this->back($app, ['err' => 'nolead'], $where);
        }

        $lead = $this->leads->update(
            $id,
            LeadStatus::isValid($status) ? $status : null,
            $notes,
            $admin->email,
        );

        if ($lead === null) {
            return $this->back($app, ['err' => 'nolead'], $where);
        }

        Audit::log($admin->email, 'lead_updated', [
            'id'     => $id,
            'status' => $lead->status,
            'from'   => $before->status,
            'notes'  => $lead->notes !== '',
        ], $app->request);

        $moved = $lead->status !== $before->status ? $lead->status : '';

        // The anchor only when the card is still on the tab it is going back
        // to; a card that moved off it has nowhere on that screen to scroll to,
        // and the toast says where it went instead.
        $stays = $where['tab'] === LeadStatus::ANY || $where['tab'] === $lead->status;

        return $this->back($app, ['updated' => true], $where + [
            'moved'  => $moved,
            'anchor' => $stays ? $id : 0,
        ]);
    }

    /* ------------------------------------------------------------ internals */

    /** One lead as the card reads it, with the destination resolved to a name. */
    private function card(Lead $lead, NameLookup $names): LeadCard
    {
        return LeadCard::fromArray([
            'id'          => $lead->id,
            'kind'        => $lead->kind,
            'quoteId'     => $lead->quoteId ?? '',
            'name'        => $lead->name,
            'phone'       => $lead->phone,
            'email'       => $lead->email,
            'message'     => $lead->message,
            'destination' => $lead->destId === null ? '' : $names->destinationName($lead->destId),
            'weightG'     => $lead->weightG ?? 0,
            'status'      => $lead->status,
            'notes'       => $lead->notes,
            'createdAt'   => $lead->createdAt->format('c'),
            'updatedAt'   => $lead->updatedAt->format('c'),
            'updatedBy'   => $lead->updatedBy,
        ]);
    }

    /** The tab being shown: one of the four statuses, or "all". */
    private function status(string $status): string
    {
        return $status === LeadStatus::ANY || LeadStatus::isValid($status) ? $status : LeadStatus::NEW;
    }

    /** A page number off the query or a form: 1 for anything that is not a sane one. */
    private static function pageNo(string $raw): int
    {
        return preg_match('/^[1-9]\d{0,4}$/', $raw) === 1 ? (int) $raw : 1;
    }

    /**
     * The ids of the countries whose names contain each word of the search, so
     * "canada" finds a lead that stored only "ca". The names are the live rate
     * card's; a country since taken off it is still found by its id.
     *
     * @return array<string,list<string>> word => destination ids
     */
    private static function destinationsNamed(string $filter): array
    {
        $words = LeadRepository::searchWords($filter);

        if ($words === []) {
            return [];
        }

        $out = [];
        foreach (Live::site()->data->card->destinations as $destination) {
            $key = Str::nameKey($destination->name);
            foreach ($words as $word) {
                if ($key !== '' && str_contains($key, $word)) {
                    $out[$word][] = $destination->id;
                }
            }
        }

        return $out;
    }

    /**
     * The one-off line after a save. Notice owns "Saved"; the tab a card moved
     * to is the one thing it cannot know, so it is added here - from the four
     * status words, never from anything typed, which is why an unknown `moved`
     * says nothing extra.
     */
    private static function toast(App $app): string
    {
        $toast = Notice::toast($app);
        $moved = $app->request->query('moved', '', 16);

        if ($toast === '' || !LeadStatus::isValid($moved)) {
            return $toast;
        }

        return $toast . ' - moved to ' . InboxPage::label($moved);
    }

    /**
     * Back to the inbox, on the tab, search and page the card was saved from.
     *
     * THE SEARCH IS THE ONE TYPED THING IN THIS URL, and it is allowed because
     * it was already in the URL: the admin typed it into a GET form, so the page
     * the card was posted from was /admin/inbox?q=... in the first place. It is
     * percent-encoded by http_build_query, so it cannot break the Location
     * header, and it never goes through Notice, whose codes stay words and
     * numbers only.
     *
     * @param array<string,int|string|bool> $codes
     * @param array{tab:string,q:string,page:int,moved?:string,anchor?:int} $where
     */
    private function back(App $app, array $codes, array $where): Response
    {
        $url = Notice::url(Routes::INBOX, $codes);

        $extra = array_filter([
            'status' => $where['tab'],
            'q'      => trim($where['q']),
            'page'   => $where['page'] > 1 ? (string) $where['page'] : '',
            'moved'  => LeadStatus::isValid($where['moved'] ?? '') ? ($where['moved'] ?? '') : '',
        ], static fn (string $v): bool => $v !== '');

        if ($extra !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
        }

        $anchor = $where['anchor'] ?? 0;
        if ($anchor > 0) {
            $url .= '#lead-' . $anchor;
        }

        return Response::redirect($app->request->basePath . $url, 303)->noStore();
    }
}
