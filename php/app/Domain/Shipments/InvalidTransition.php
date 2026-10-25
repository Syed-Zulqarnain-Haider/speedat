<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

/**
 * A status change the transition table refuses.
 *
 * It carries both statuses so the admin gets a sentence it can act on -
 * "A shipment that is Booked cannot go straight to Delivered" - rather than a
 * generic failure that leaves the operator guessing which button was wrong.
 *
 * It is a distinct type, not a plain InvalidArgumentException, because the
 * caller reacts to it differently: a refused transition is a message on the
 * form, and everything else is a 500.
 */
final class InvalidTransition extends \DomainException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct(sprintf(
            'A shipment that is %s cannot go straight to %s.',
            ShipmentStatus::label($from),
            ShipmentStatus::label($to),
        ));
    }
}
