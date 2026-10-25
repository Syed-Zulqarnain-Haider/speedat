<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * What one destination charges for one service, as the table's input boxes.
 *
 * Every property is a finished field name and a finished string value: the
 * template prints them and nothing else. A blank value means "not offered"
 * and must stay blank - printing 0 there would tell the owner he charges
 * nothing for a service he does not run.
 *
 * THERE IS NO DOCUMENT BOX. The owner removed the flat document rate from the
 * product on 2026-09-27, so a service has prices by weight and nothing else.
 */
final class RateCells
{
    /** @param list<array{kg:int,name:string,value:string,label:string,low:bool,gap:bool,edited:bool}> $boxes */
    public function __construct(
        public readonly string $serviceId,
        public readonly string $serviceName,
        public readonly string $firstField,
        public readonly string $firstValue,
        public readonly string $addlField,
        public readonly string $addlValue,
        public readonly string $daysField,
        public readonly string $daysValue,
        /** One per whole kilogram in grid mode; empty in slab mode. */
        public readonly array $boxes = [],
        /** "4,500 - 21,000 (12/25)" or an em dash when nothing is priced. */
        public readonly string $gridSummary = "-",
        /** Quick fill writes start + i x step into these boxes; the names the two inputs post under. */
        public readonly string $fillStartField = '',
        public readonly string $fillStepField = '',
        /** The id of this ladder's note, which every flagged box points at with aria-describedby. */
        public readonly string $ladderId = '',
        /** What the ladder needs said, in one sentence; "" when nothing does. */
        public readonly string $ladderNote = '',
        /** True when something is WRONG (a rung priced below the one under it), not merely blank. */
        public readonly bool $ladderFault = false,
        /** How many kilograms carry a price, and how many there are. */
        public readonly int $ladderFilled = 0,
        public readonly int $ladderTotal = 0,
    ) {
    }

    /** "12 of 25 priced" / "all 25 priced" - the quiet line beside the service name. */
    public function ladderCount(): string
    {
        if ($this->ladderTotal === 0) {
            return '';
        }

        return $this->ladderFilled === $this->ladderTotal
            ? 'all ' . $this->ladderTotal . ' priced'
            : $this->ladderFilled . ' of ' . $this->ladderTotal . ' priced';
    }

    /**
     * The class the ladder note wears. Three tones, not two, on purpose:
     * a ladder that runs backwards is WRONG and is orange, a ladder with
     * blanks in it is merely SPARSE and is stated quietly, and a sound ladder
     * says nothing at all. An alarm that fires on the ordinary case is an
     * alarm the owner learns to look past.
     */
    public function ladderTone(): string
    {
        if ($this->ladderFault) {
            return 'warn';
        }

        return $this->ladderNote === '' ? 'ok' : 'info';
    }
}
