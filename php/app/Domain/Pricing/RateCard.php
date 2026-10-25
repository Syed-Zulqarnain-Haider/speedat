<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * Everything a price needs: the services sold, the destinations served and the
 * settings that turn a weight into money. Port of `RateCard` in types.ts.
 */
final class RateCard
{
    /**
     * @param list<Service>     $services
     * @param list<Destination> $destinations
     */
    public function __construct(
        public readonly array $services,
        public readonly array $destinations,
        public readonly Settings $settings,
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $services = [];
        if (isset($a['services']) && is_array($a['services'])) {
            foreach ($a['services'] as $service) {
                if (is_array($service)) {
                    $services[] = Service::fromArray($service);
                }
            }
        }

        $destinations = [];
        if (isset($a['destinations']) && is_array($a['destinations'])) {
            foreach ($a['destinations'] as $destination) {
                if (is_array($destination)) {
                    $destinations[] = Destination::fromArray($destination);
                }
            }
        }

        return new self(
            services: $services,
            destinations: $destinations,
            settings: Settings::fromArray(is_array($a['settings'] ?? null) ? $a['settings'] : []),
        );
    }

    /** The destination with this id, shown or hidden. */
    public function destination(string $id): ?Destination
    {
        foreach ($this->destinations as $destination) {
            if ($destination->id === $id) {
                return $destination;
            }
        }

        return null;
    }

    /** The destination the site may price: found AND shown. */
    public function activeDestination(string $id): ?Destination
    {
        foreach ($this->destinations as $destination) {
            if ($destination->id === $id && $destination->active) {
                return $destination;
            }
        }

        return null;
    }

    public function service(string $id): ?Service
    {
        foreach ($this->services as $service) {
            if ($service->id === $id) {
                return $service;
            }
        }

        return null;
    }
}
