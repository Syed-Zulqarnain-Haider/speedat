<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * A row was about to be written with a value wider than its column.
 *
 * Thrown only by Caps::assert(). It exists so that a repository can guard a
 * write with ONE line and still let the route above it print per-field messages:
 *
 *     try {
 *         Caps::assert('leads', $row);
 *     } catch (ValueTooLong $e) {
 *         return $this->form($e->problems);   // column => sentence
 *     }
 *
 * WHY A TYPE OF ITS OWN. The alternative is letting the PDOException happen,
 * which is a 500 page, and the alternative to that is `catch (\Throwable)`,
 * which also swallows the connection dying. A caller that wants to turn "too
 * long" into a form message and nothing else needs a class it can name.
 *
 * It extends \LengthException because that is what the SPL already means by
 * this, so code that catches \LengthException catches it too.
 *
 * THE MESSAGE IS THE CUSTOMER'S SENTENCE, not a developer's. It names the field
 * and the limit and never quotes the value back - the value may be somebody's
 * address, and an exception message ends up in a log.
 */
final class ValueTooLong extends \LengthException
{
    /**
     * @param string               $table    the table the row was for
     * @param array<string,string> $problems column => the sentence for that field
     */
    public function __construct(
        public readonly string $table,
        public readonly array $problems,
    ) {
        parent::__construct($problems === [] ? 'A value was too long.' : (string) reset($problems));
    }

    /** The columns that did not fit, for a log line. Never the values. */
    public function columns(): string
    {
        return implode(', ', array_keys($this->problems));
    }
}
