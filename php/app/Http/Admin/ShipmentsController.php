<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\HttpError;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Core\ValueTooLong;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Shipments\InvalidTransition;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\Domain\Shipments\Shipment;
use Speedat\Domain\Shipments\ShipmentId;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Domain\SiteDoc\Live;
use Speedat\View\Admin\NewShipmentPage;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\ShipmentPage;
use Speedat\View\Admin\ShipmentsPage;

/**
 * The shipment desk: the ledger, one shipment's details, and its timeline.
 *
 * TWO POSTS, NOT ONE, AND THEY ARE DIFFERENT KINDS OF WRITE. "Save details" is a
 * correction - a phone number, a tracking number - and writes six columns.
 * "Add update" is an EVENT: it moves the status, writes a row the customer's
 * WhatsApp message is built from, and is refused when the move is not one the
 * transition table allows. Merging them would mean every typo fix wrote a
 * timeline entry, and every status change needed the whole form.
 *
 * THE NAMES ARE THE ONES IT WAS BOOKED UNDER. A shipment stores destination and
 * service IDS; a country taken off the rate card still has shipments in the air.
 * `Shipments\NameLookup` reads the live document first and then the most recent
 * version that still carried the id, so "the country this was sold as" and "the
 * country we sell today" stay different questions.
 *
 * A MADE-UP ID IS A 404, NEVER A 500. `ShipmentId` decides what an id can look
 * like before the repository is asked - it throws on a malformed one - so
 * `/admin/shipments/1 OR 1=1` is a page-not-found and nothing reaches SQL that
 * is not a parameter.
 */
final class ShipmentsController
{
    /** How many rows one screen of the ledger holds. */
    private const PAGE = 200;

    public function __construct(
        private readonly ShipmentRepository $shipments = new ShipmentRepository(),
        private readonly LeadRepository $leads = new LeadRepository(),
    ) {
    }

    /** GET /admin/shipments */
    public function index(App $app, AdminUser $admin): Response
    {
        $status = $this->listStatus($app->request->query('status', ShipmentRepository::OPEN, 24));
        $filter = $app->request->query('q', '', 80);

        $rows   = $this->shipments->list($status, self::PAGE);
        $names  = new NameLookup();
        $needle = Str::nameKey($filter);

        $table = [];
        foreach ($rows as $shipment) {
            $row = [
                'id'            => $shipment->id,
                'quoteId'       => $shipment->quoteId ?? '',
                'customerName'  => $shipment->customerName,
                'customerPhone' => $shipment->customerPhone,
                'destination'   => $names->destinationName($shipment->destId),
                'service'       => $names->serviceName($shipment->serviceId),
                'carrier'       => $shipment->carrier,
                'trackingNo'    => $shipment->trackingNo ?? '',
                'status'        => $shipment->status,
                'updatedAt'     => $shipment->updatedAt->format('c'),
            ];

            $key = Str::nameKey($row['id'] . ' ' . $row['customerName'] . ' ' . $row['destination'] . ' ' . $row['trackingNo'] . ' ' . $row['carrier']);

            if ($needle !== '' && !str_contains($key, $needle)) {
                continue;
            }

            $table[] = $row;
        }

        $page = new ShipmentsPage(
            rows: $table,
            counts: $this->shipments->counts(),
            status: $status,
            filter: $filter,
        );

        return $app->page('admin/shipments', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'shipments'),
            'theme' => Screen::theme($app),
        ]);
    }

    /** GET /admin/shipments/new - optionally from a lead in the inbox. */
    public function create(App $app, AdminUser $admin): Response
    {
        $leadId = (int) $app->request->query('lead', '0', 12);
        $lead   = $leadId > 0 ? $this->leads->find($leadId) : null;

        $values = [];
        if ($lead !== null) {
            // The office should not retype what the customer already told us: a
            // retyped phone number is a wrong phone number.
            $values = [
                'customerName'  => $lead->name,
                'customerPhone' => $lead->phone,
                'destId'        => $lead->destId ?? '',
                'notes'         => $lead->message,
            ];
        }

        return $this->newForm($app, $admin, $values, $lead === null ? 0 : $lead->id, $lead?->quoteId ?? '');
    }

    /** POST /admin/shipments/create */
    public function store(App $app, AdminUser $admin): Response
    {
        $values = [
            'customerName'  => $app->request->post('customerName', '', 120),
            'customerPhone' => $app->request->post('customerPhone', '', 40),
            'receiverName'  => $app->request->post('receiverName', '', 120),
            'destId'        => $app->request->post('destId', '', 64),
            'serviceId'     => $app->request->post('serviceId', '', 64),
            'carrier'       => $app->request->post('carrier', '', 80),
            'trackingNo'    => $app->request->post('trackingNo', '', 64),
            'notes'         => $app->request->post('notes', '', 4000),
        ];

        $leadId  = $app->request->postInt('leadId');
        $quoteId = $app->request->post('quoteId', '', 32);

        $card = Live::site()->data->card;

        // The country and the service have to be ones this site sells today: the
        // form offered exactly those, and a post that names anything else was not
        // made by the form.
        if ($card->destination($values['destId']) === null) {
            return $this->newForm($app, $admin, $values, $leadId, $quoteId, 'Choose a destination.');
        }

        if ($card->service($values['serviceId']) === null) {
            return $this->newForm($app, $admin, $values, $leadId, $quoteId, 'Choose a service.');
        }

        try {
            $shipment = $this->shipments->create(
                leadId: $leadId > 0 ? $leadId : null,
                quoteId: $quoteId !== '' ? $quoteId : null,
                customerName: $values['customerName'],
                customerPhone: $values['customerPhone'],
                receiverName: $values['receiverName'],
                destId: $values['destId'],
                serviceId: $values['serviceId'],
                carrier: $values['carrier'],
                trackingNo: $values['trackingNo'],
                notes: $values['notes'],
                by: $admin->email,
            );
        } catch (ValueTooLong $e) {
            return $this->newForm($app, $admin, $values, $leadId, $quoteId, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            Log::warn('shipment_create_refused', ['reason' => $e->getMessage()]);

            return $this->newForm($app, $admin, $values, $leadId, $quoteId, 'That shipment could not be created.');
        }

        Audit::log($admin->email, 'shipment_created', [
            'id'      => $shipment->id,
            'leadId'  => $leadId > 0 ? $leadId : null,
            'quoteId' => $quoteId !== '' ? $quoteId : null,
            'destId'  => $shipment->destId,
        ], $app->request);

        return $this->toDesk($app, $shipment->id, ['created' => true]);
    }

    /** GET /admin/shipments/{id} - the desk. */
    public function desk(App $app, AdminUser $admin, string $id): Response
    {
        $shipment = $this->findOr404($id);
        $history  = $this->shipments->history($shipment->id);

        if ($history === null) {
            throw HttpError::notFound(['shipment' => $shipment->id]);
        }

        $names = new NameLookup();

        $events = [];
        foreach ($history->events as $event) {
            $events[] = [
                'id'     => $event->id,
                'status' => $event->status,
                'note'   => $event->note,
                'at'     => $event->at->format('c'),
                'by'     => $event->by,
            ];
        }

        $page = new ShipmentPage(
            shipment: [
                'id'            => $shipment->id,
                'quoteId'       => $shipment->quoteId ?? '',
                'customerName'  => $shipment->customerName,
                'customerPhone' => $shipment->customerPhone,
                'receiverName'  => $shipment->receiverName,
                'carrier'       => $shipment->carrier,
                'trackingNo'    => $shipment->trackingNo ?? '',
                'notes'         => $shipment->notes,
                'status'        => $shipment->status,
                'createdAt'     => $shipment->createdAt->format('c'),
                'destination'   => $names->destinationName($shipment->destId),
                'service'       => $names->serviceName($shipment->serviceId),
            ],
            events: $events,
            companyName: Screen::companyName(),
            notice: $this->deskNotice($app),
        );

        return $app->page('admin/shipment', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'shipments'),
            'theme' => Screen::theme($app),
        ]);
    }

    /** POST /admin/shipments/update - the details, not the status. */
    public function update(App $app, AdminUser $admin): Response
    {
        $shipment = $this->findOr404($app->request->post('id', '', 32));

        $patch = [
            'customerName'  => $app->request->post('customerName', '', 120),
            'customerPhone' => $app->request->post('customerPhone', '', 40),
            'receiverName'  => $app->request->post('receiverName', '', 120),
            'carrier'       => $app->request->post('carrier', '', 80),
            'trackingNo'    => $app->request->post('trackingNo', '', 64),
            'notes'         => $app->request->post('notes', '', 4000),
        ];

        try {
            $this->shipments->update($shipment->id, $patch, $admin->email);
        } catch (ValueTooLong) {
            return $this->toDesk($app, $shipment->id, ['err' => 'toolong']);
        }

        Audit::log($admin->email, 'shipment_updated', [
            'id'     => $shipment->id,
            'fields' => array_keys(array_filter($patch, static fn (string $v): bool => $v !== '')),
        ], $app->request);

        return $this->toDesk($app, $shipment->id, ['updated' => true]);
    }

    /** POST /admin/shipments/event - move the status and write the timeline entry. */
    public function event(App $app, AdminUser $admin): Response
    {
        $shipment = $this->findOr404($app->request->post('id', '', 32));

        $status = $app->request->post('status', '', 24);
        $note   = $app->request->post('note', '', 1000);

        if (!ShipmentStatus::isValid($status)) {
            return $this->toDesk($app, $shipment->id, ['err' => 'badstatus']);
        }

        try {
            $event = $this->shipments->addEvent($shipment->id, $status, $note, $admin->email);
        } catch (InvalidTransition $e) {
            Log::warn('shipment_transition_refused', ['id' => $shipment->id, 'reason' => $e->getMessage()]);

            return $this->toDesk($app, $shipment->id, ['err' => 'badstatus']);
        } catch (ValueTooLong) {
            return $this->toDesk($app, $shipment->id, ['err' => 'toolong']);
        }

        if ($event === null) {
            return $this->toDesk($app, $shipment->id, ['err' => 'noshipment']);
        }

        Audit::log($admin->email, 'shipment_event', [
            'id'     => $shipment->id,
            'status' => $status,
            'note'   => $note !== '',
        ], $app->request);

        return $this->toDesk($app, $shipment->id, ['event' => true]);
    }

    /* ------------------------------------------------------------ internals */

    /** The new-shipment form, fresh or re-filled after a refusal. @param array<string,string> $values */
    private function newForm(App $app, AdminUser $admin, array $values, int $leadId, string $quoteId, string $error = ''): Response
    {
        $card = Live::site()->data->card;

        $destinations = [];
        foreach ($card->destinations as $dest) {
            if ($dest->active) {
                $destinations[] = ['id' => $dest->id, 'name' => $dest->name];
            }
        }
        usort($destinations, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $services = [];
        foreach ($card->services as $service) {
            $services[] = ['id' => $service->id, 'name' => $service->name];
        }

        $page = new NewShipmentPage(
            destinations: $destinations,
            services: $services,
            values: $values,
            leadId: max(0, $leadId),
            quoteId: $quoteId,
            error: $error,
        );

        return $app->page('admin/shipment-new', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'shipments'),
            'theme' => Screen::theme($app),
        ], $error === '' ? 200 : 422);
    }

    /** The shipment, or a 404 - for a missing one AND for an id that cannot exist. */
    private function findOr404(string $raw): Shipment
    {
        $id = ShipmentId::normalise($raw);

        if ($id === null) {
            throw HttpError::notFound(['shipment' => 'malformed']);
        }

        $shipment = $this->shipments->find($id);

        if ($shipment === null) {
            throw HttpError::notFound(['shipment' => $id]);
        }

        return $shipment;
    }

    /** open | all | one of the seven statuses. */
    private function listStatus(string $status): string
    {
        if ($status === ShipmentRepository::ANY || ShipmentStatus::isValid($status)) {
            return $status;
        }

        return ShipmentRepository::OPEN;
    }

    /** The line at the top of the desk after an action. */
    private function deskNotice(App $app): string
    {
        $error = Notice::error($app);

        if ($error !== '') {
            return $error;
        }

        return Notice::toast($app);
    }

    /** @param array<string,int|string|bool> $codes */
    private function toDesk(App $app, string $id, array $codes): Response
    {
        return Response::redirect(
            $app->request->basePath . Notice::url(Routes::shipment($id), $codes),
            303,
        )->noStore();
    }
}
