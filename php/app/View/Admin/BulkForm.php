<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Service;

/**
 * Bulk adjust: change many prices at once, for example when a carrier raises
 * everything by 5%. Port of BulkAdjust.tsx.
 *
 * The React version did the arithmetic in the browser. This one posts and the
 * server does it, for the reason the whole admin works that way: the draft is
 * in MySQL, and a price the browser computed is a price nobody validated.
 * `admin.js` only repeats the two refusals ("choose a service", "enter a
 * value") so the owner is not told by a round trip that he ticked nothing.
 */
final class BulkForm
{
    public const BY_PERCENT = 'pct';
    public const BY_AMOUNT  = 'amt';

    /**
     * @param list<Service>       $services
     * @param array<string,bool>  $servicesOn service id => ticked
     * @param array<string,bool>  $fieldsOn   first|addl => ticked
     */
    public function __construct(
        public readonly array $services,
        public readonly array $servicesOn = [],
        public readonly array $fieldsOn = ['first' => true, 'addl' => true],
        public readonly string $mode = self::BY_PERCENT,
        public readonly string $value = '',
        public readonly string $roundTo = '10',
        /** ok|warn - the class of the notice under the form. */
        public readonly string $messageKind = '',
        public readonly string $message = '',
        public readonly bool $readOnly = false,
    ) {
    }

    /** Every service starts ticked, as it does in the React form. */
    /** @param list<Service> $services */
    public static function fresh(array $services, bool $readOnly = false): self
    {
        $on = [];
        foreach ($services as $service) {
            $on[$service->id] = true;
        }

        return new self(services: $services, servicesOn: $on, readOnly: $readOnly);
    }

    public function serviceOn(string $id): bool
    {
        return $this->servicesOn[$id] ?? false;
    }

    public function fieldOn(string $key): bool
    {
        return $this->fieldsOn[$key] ?? false;
    }

    public function hasMessage(): bool
    {
        return $this->message !== '';
    }

    /**
     * The price fields, in the order the form lists them.
     *
     * "Documents" was the third. The owner removed the flat document rate from
     * the product on 2026-09-27, so there is no such price to raise by 5%.
     * "First slab" also carries the per-kilogram boxes; the server's `bulk`
     * action applies the same arithmetic to the grid when `first` is ticked.
     *
     * @return list<array{key:string,label:string}>
     */
    public function fields(): array
    {
        return [
            ['key' => 'first', 'label' => 'First slab and every per-kilogram price'],
            ['key' => 'addl', 'label' => 'Per step'],
        ];
    }

    /** @return array<string,string> */
    public function modes(): array
    {
        return [self::BY_PERCENT => 'by percent', self::BY_AMOUNT => 'by amount'];
    }
}
