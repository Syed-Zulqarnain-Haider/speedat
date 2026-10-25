<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The "Review and publish" card: what is about to go live, what blocks it,
 * and what is merely worth looking at twice. Port of the review block in
 * AdminEditor.tsx.
 *
 * Errors and warnings are different things and are not merged. An error
 * ("Volumetric divisor must be between 1000 and 10000") blocks publishing and
 * the card is replaced by the list of them. A warning ("Canada Express 1 kg
 * is 38% above the live price") never blocks - only the owner can tell a typo
 * from a deliberate discount - so it sits inside the card above the button.
 */
final class Review
{
    /** Beyond this many warnings the list says "...and N more" rather than running off the page. */
    private const MAX_WARNINGS = 15;

    /**
     * @param list<string>   $errors   blocking problems; publishing is refused while any exist
     * @param list<DiffLine> $lines    the change list, old struck through and new bold
     * @param list<string>   $warnings non-blocking, shown above the button
     */
    public function __construct(
        public readonly int $nextVersion,
        public readonly array $errors = [],
        public readonly array $lines = [],
        public readonly array $warnings = [],
        /** Prices are currently off the website. */
        public readonly bool $holdOn = false,
        /** The "Rates are live" tick is offered only while the site still shows sample rates. */
        public readonly bool $offerGoLive = false,
        public readonly bool $goLive = true,
        /** "Show prices again after publishing" - offered only while a hold is on. */
        public readonly bool $resume = true,
        /** The publish outcome, shown under the buttons ("Published version 12. ..."). */
        public readonly string $message = '',
    ) {
    }

    public function blocked(): bool
    {
        return $this->errors !== [];
    }

    public function changeCount(): int
    {
        return count($this->lines);
    }

    /** "3 changes." / "1 change." - the first sentence of the card's description. */
    public function countSentence(): string
    {
        $n = $this->changeCount();

        return $n . ' change' . ($n === 1 ? '' : 's') . '.';
    }

    /** The sentence after it: what publishing will mean for customers right now. */
    public function effectSentence(): string
    {
        return $this->holdOn
            ? 'Prices are on hold; choose below whether this publish shows them again.'
            : 'Customers see the new prices as soon as it is published.';
    }

    /** @return list<string> */
    public function warningsShown(): array
    {
        return array_slice($this->warnings, 0, self::MAX_WARNINGS);
    }

    public function moreWarnings(): int
    {
        return max(0, count($this->warnings) - self::MAX_WARNINGS);
    }
}
