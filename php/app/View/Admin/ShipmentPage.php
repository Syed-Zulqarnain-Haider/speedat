<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Shipments\ShipmentStatus as Transitions;
use Speedat\Domain\Tracking\Carriers;

/**
 * One shipment's desk: the details that save when you leave them, the status
 * advance, the timeline, and the WhatsApp update the customer receives. Port
 * of ShipmentDesk.tsx.
 *
 * The update text is built here, on the server, and printed into the page
 * twice: once inside the wa.me link and once in a copy buffer. A message the
 * browser assembled could differ from the one the timeline records; this one
 * cannot.
 */
final class ShipmentPage
{
    /**
     * @param array{id:string,quoteId:string,customerName:string,customerPhone:string,receiverName:string,carrier:string,trackingNo:string,notes:string,status:string,createdAt:string,destination:string,service:string} $shipment
     * @param list<array{id:int,status:string,note:string,at:string,by:string}>                                                                                                                                           $events oldest first
     */
    public function __construct(
        public readonly array $shipment,
        public readonly array $events,
        public readonly string $companyName,
        /** A message from the last action, shown as a notice. */
        public readonly string $notice = '',
        /**
         * ok | err. A refusal used to share the green role=status box with
         * "Update added", so it read as a success; an err is role=alert.
         */
        public readonly string $noticeKind = 'ok',
        /** The update note as typed, put back after a refused update. */
        public readonly string $note = '',
        /** The customer's /track link, or '' when config.php has no app.url. */
        public readonly string $trackUrl = '',
        /** One plain sentence on automatic tracking, for the office. */
        public readonly string $trackingLine = '',
    ) {
    }

    public function noticeIsError(): bool
    {
        return $this->noticeKind === 'err';
    }

    /**
     * The statuses the select offers: exactly the moves the transition table
     * allows from where the shipment is now, staying put included, labelled
     * as the admin reads them.
     *
     * Not all seven. The server refuses every other move (ShipmentRepository
     * ::addEvent), so offering them was a dropdown of buttons that always
     * fail - Delivered from Booked, picked, typed a note for, and refused.
     * The table is the domain's, so the offer and the refusal cannot drift.
     *
     * @return array<string,string> value => label
     */
    public function statusOptions(): array
    {
        $out = [];
        foreach (Transitions::nextFrom($this->currentStatus()) as $status) {
            $out[$status] = ShipmentStatus::label($status);
        }

        return $out;
    }

    public function id(): string
    {
        return $this->shipment['id'];
    }

    public function eyebrow(): string
    {
        return "03 - Shipment";
    }

    /**
     * "Express to Canada" - the short line beside the heading, which is all
     * there is room for at the top of the page.
     */
    public function route(): string
    {
        return $this->shipment['service'] . ' to ' . $this->shipment['destination'];
    }

    /** "Express to Canada - created 23 Sept 2026, 14:05 - quote SP-260923-K7QX". */
    public function summary(): string
    {
        $text = $this->route();
        $when = Format::fmtDateTime($this->shipment['createdAt']);
        if ($when !== '') {
            $text .= " \u{b7} created " . $when;
        }
        if ($this->shipment['quoteId'] !== '') {
            $text .= " \u{b7} quote " . $this->shipment['quoteId'];
        }

        return $text;
    }

    public function currentStatus(): string
    {
        return ShipmentStatus::current($this->shipment['status']);
    }

    public function currentLabel(): string
    {
        return ShipmentStatus::label($this->currentStatus());
    }

    /** The editable details, in the order the panel lists them. */
    /** @return list<Field> */
    public function detailFields(): array
    {
        return [
            Field::text('customerName', 'Customer name', $this->shipment['customerName'], '', '', 120),
            Field::tel('customerPhone', 'Customer phone / WhatsApp', $this->shipment['customerPhone']),
            Field::text('receiverName', 'Receiver name', $this->shipment['receiverName'], '', '', 120),
            // Tracked automatically when it names one of Tracking\Carriers;
            // the hint lists them so "DHL" is typed, not "courier".
            Field::text('carrier', 'Carrier / airline', $this->shipment['carrier'], 'e.g. DHL', 'Tracked automatically: ' . implode(', ', Carriers::trackedNames()) . '.', 80),
            // `.track` is admin.css's monospace, tabular box. A tracking number
            // is read back a digit at a time off a phone screen; proportional
            // digits are how a 1 gets typed where a 7 was meant.
            // 64, not the React form's 80: `shipments.tracking_no` is
            // VARCHAR(64), and a box that accepts more than the column holds
            // is a truncated tracking number nobody can trace.
            Field::text('trackingNo', 'Tracking number', $this->shipment['trackingNo'], '', '', 64, false, 'track'),
            Field::area('notes', 'Internal notes', $this->shipment['notes'], '', '', false, 4000),
        ];
    }

    /** The message the customer gets, as the timeline currently reads. */
    public function customerMessage(): string
    {
        $events = [];
        foreach ($this->events as $event) {
            $events[] = ['status' => $event['status'], 'note' => $event['note']];
        }

        return ShipmentStatus::customerMessage($this->companyName, [
            'id'           => $this->shipment['id'],
            'customerName' => $this->shipment['customerName'],
            'destination'  => $this->shipment['destination'],
            'status'       => $this->shipment['status'],
            'trackingNo'   => $this->shipment['trackingNo'],
            'carrier'      => $this->shipment['carrier'],
        ], $events, $this->trackUrl);
    }

    /** The wa.me link with the update already in it, or "" without a usable number. */
    public function waHref(): string
    {
        return Wa::hrefWithText($this->shipment['customerPhone'], $this->customerMessage());
    }

    /**
     * The timeline, newest first, with the labels and times resolved.
     *
     * @return list<array{status:string,label:string,note:string,at:string,by:string}>
     */
    public function timeline(): array
    {
        $out = [];
        foreach (array_reverse($this->events) as $event) {
            $status = ShipmentStatus::current($event['status']);
            $out[]  = [
                'status' => $status,
                'label'  => ShipmentStatus::label($status),
                'note'   => $event['note'],
                'at'     => Format::fmtDateTime($event['at']),
                'by'     => $event['by'],
            ];
        }

        return $out;
    }
}
