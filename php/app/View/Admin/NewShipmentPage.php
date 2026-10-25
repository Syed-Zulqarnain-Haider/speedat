<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * "New shipment" - raised from a lead in the inbox, or from nothing. Port of
 * NewShipmentForm.tsx.
 *
 * When it comes from a lead, the customer's name, number, destination and
 * message are already in the boxes: the office should not retype what the
 * customer already told us, and a retyped phone number is a wrong phone
 * number.
 */
final class NewShipmentPage
{
    /**
     * @param list<array{id:string,name:string}> $destinations A-Z
     * @param list<array{id:string,name:string}> $services
     * @param array<string,string>               $values       the posted values to re-fill after a refusal
     */
    public function __construct(
        public readonly array $destinations,
        public readonly array $services,
        public readonly array $values = [],
        public readonly int $leadId = 0,
        public readonly string $quoteId = '',
        /** Why the last attempt was refused, "" on a fresh form. */
        public readonly string $error = '',
    ) {
    }

    public function eyebrow(): string
    {
        return "03 - Shipments";
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /** @return array<string,string> value => label */
    public function destinationOptions(): array
    {
        $out = [];
        foreach ($this->destinations as $dest) {
            $out[$dest['id']] = $dest['name'];
        }

        return $out;
    }

    /** @return array<string,string> value => label */
    public function serviceOptions(): array
    {
        $out = [];
        foreach ($this->services as $service) {
            $out[$service['id']] = $service['name'];
        }

        return $out;
    }
}
