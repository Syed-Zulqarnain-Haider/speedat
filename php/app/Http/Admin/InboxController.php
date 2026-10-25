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
 * as the office types, and with scripting off the server filters the list here.
 * Both compare the same accent-free key (`Str::nameKey`), so "turkiye" finds
 * "Türkiye" whichever side is doing the work.
 *
 * SAVING IS A POST, A WRITE AND A REDIRECT. The React list patched the row in
 * place over a server action; here the card posts, the server validates, and the
 * page comes back - slower by one load, and worth it on the office laptop with
 * scripting blocked.
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

        $leads  = $this->leads->list($status, self::PAGE);
        $names  = new NameLookup();
        $needle = Str::nameKey($filter);

        $cards = [];
        foreach ($leads as $lead) {
            $card = $this->card($lead, $names);

            if ($needle !== '' && !str_contains(Str::nameKey($card->searchKey()), $needle)) {
                continue;
            }

            $cards[] = $card;
        }

        $counts = $this->leads->counts()->toArray();

        $page = new InboxPage(
            leads: $cards,
            counts: $counts,
            status: $status,
            // A deployment that cannot email says so on the page rather than
            // losing messages quietly: they still land here.
            alertsConfigured: $app->mail()->configured(),
            filter: $filter,
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

        if ($id < 1) {
            return $this->back($app, ['err' => 'nolead']);
        }

        $before = $this->leads->find($id);

        if ($before === null) {
            return $this->back($app, ['err' => 'nolead']);
        }

        $lead = $this->leads->update(
            $id,
            LeadStatus::isValid($status) ? $status : null,
            $notes,
            $admin->email,
        );

        if ($lead === null) {
            return $this->back($app, ['err' => 'nolead']);
        }

        Audit::log($admin->email, 'lead_updated', [
            'id'     => $id,
            'status' => $lead->status,
            'from'   => $before->status,
            'notes'  => $lead->notes !== '',
        ], $app->request);

        return $this->back($app, ['updated' => true]);
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

    /** @param array<string,int|string|bool> $codes */
    private function back(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::INBOX, $codes), 303)->noStore();
    }
}
