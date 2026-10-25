<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;

/**
 * The shipments list. Port of app/admin/shipments/page.tsx.
 *
 * A destination or service that has since been removed from the rates keeps
 * the name it was booked under: the caller resolves the names and hands them
 * over, because "the country this was sold as" is not the same question as
 * "the country we sell today".
 */
final class ShipmentsPage
{
    /**
     * @param list<array{id:string,quoteId:string,customerName:string,customerPhone:string,destination:string,service:string,carrier:string,trackingNo:string,status:string,updatedAt:string}> $rows
     * @param array<string,int> $counts open|exception|... => how many
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $counts = [],
        /** open | all | one of the seven statuses. */
        public readonly string $status = 'open',
        public readonly string $filter = '',
    ) {
    }

    public function eyebrow(): string
    {
        return "03 - Shipments";
    }

    public function openCount(): int
    {
        return $this->counts['open'] ?? 0;
    }

    public function exceptionCount(): int
    {
        return $this->counts['exception'] ?? 0;
    }

    /** "2 need attention" - the pill beside the heading, "" when none do. */
    public function attentionPill(): string
    {
        $n = $this->exceptionCount();

        return $n > 0 ? $n . ' need attention' : '';
    }

    /**
     * Open, then each status, then All - the tab row.
     *
     * @return list<array{key:string,label:string,count:int,href:string,active:bool}>
     */
    public function tabs(): array
    {
        $tabs = [[
            'key'    => 'open',
            'label'  => 'Open',
            'count'  => $this->openCount(),
            'href'   => Routes::shipments('open'),
            'active' => $this->status === 'open',
        ]];

        foreach (ShipmentStatus::ALL as $status) {
            $tabs[] = [
                'key'    => $status,
                'label'  => ShipmentStatus::label($status),
                'count'  => 0,
                'href'   => Routes::shipments($status),
                'active' => $this->status === $status,
            ];
        }

        $tabs[] = [
            'key'    => 'all',
            'label'  => 'All',
            'count'  => 0,
            'href'   => Routes::shipments('all'),
            'active' => $this->status === 'all',
        ];

        return $tabs;
    }

    /**
     * The rows with everything the table prints already resolved: the status
     * label and pill class, the link, the formatted time.
     *
     * @return list<array<string,string>>
     */
    public function table(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $status = ShipmentStatus::current($row['status']);
            $out[]  = [
                'id'            => $row['id'],
                'href'          => Routes::shipment($row['id']),
                'quoteId'       => $row['quoteId'],
                'customerName'  => $row['customerName'] !== '' ? $row['customerName'] : "-",
                'customerPhone' => $row['customerPhone'],
                'destination'   => $row['destination'],
                'service'       => $row['service'],
                'carrier'       => $row['carrier'] !== '' ? $row['carrier'] : "-",
                'trackingNo'    => $row['trackingNo'],
                'status'        => $status,
                'statusLabel'   => ShipmentStatus::label($status),
                'updatedAt'     => Format::fmtDateTime($row['updatedAt']),
                'needsAttention' => $status === 'exception' ? '1' : '',
                'searchKey'     => trim($row['id'] . ' ' . $row['customerName'] . ' ' . $row['destination'] . ' ' . $row['trackingNo'] . ' ' . $row['carrier']),
            ];
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
