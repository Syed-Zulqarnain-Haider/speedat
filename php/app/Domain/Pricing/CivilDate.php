<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * A calendar day - year, month, day - with no time and no time zone.
 *
 * The TypeScript builds delivery estimates out of `new Date(y, m, d)` and
 * `setDate()`, which is pure calendar arithmetic: no hours, no daylight
 * saving, no offsets. Reproducing that with PHP's \DateTimeImmutable would
 * drag a time zone into every comparison and one forgotten conversion would
 * move a delivery date by a day.
 *
 * So this does the arithmetic the way an almanac does it, on the Julian day
 * number: two integer conversions, addition in days, and a modulo for the day
 * of the week. No 32-bit timestamp limit, no zones, nothing to configure.
 */
final class CivilDate
{
    public function __construct(
        public readonly int $y,
        public readonly int $m,
        public readonly int $d,
    ) {
    }

    /** From a Julian day number. */
    public static function fromJdn(int $jdn): self
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $e = intdiv(4 * $c + 3, 1461);
        $f = $c - intdiv(1461 * $e, 4);
        $g = intdiv(5 * $f + 2, 153);

        return new self(
            100 * $b + $e - 4800 + intdiv($g, 10),
            $g + 3 - 12 * intdiv($g, 10),
            $f - intdiv(153 * $g + 2, 5) + 1,
        );
    }

    /** "YYYY-MM-DD", or null when the text is not one. */
    public static function fromIsoDate(?string $value): ?self
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /** Today, in whatever zone the \DateTimeInterface carries. */
    public static function fromDateTime(\DateTimeInterface $at): self
    {
        return new self((int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j'));
    }

    public function jdn(): int
    {
        $a  = intdiv(14 - $this->m, 12);
        $y  = $this->y + 4800 - $a;
        $m  = $this->m + 12 * $a - 3;

        return $this->d
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    /** 0 = Sunday, matching JavaScript's Date#getDay(). */
    public function dayOfWeek(): int
    {
        return ($this->jdn() + 1) % 7;
    }

    public function addDays(int $n): self
    {
        return self::fromJdn($this->jdn() + $n);
    }

    /** "YYYY-MM-DD" - the key holidays are looked up by, and what <input type="date"> wants. */
    public function key(): string
    {
        return sprintf('%04d-%02d-%02d', $this->y, $this->m, $this->d);
    }

    public function equals(self $other): bool
    {
        return $this->y === $other->y && $this->m === $other->m && $this->d === $other->d;
    }

    public function isBefore(self $other): bool
    {
        return $this->jdn() < $other->jdn();
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
