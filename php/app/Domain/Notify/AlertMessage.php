<?php

declare(strict_types=1);

namespace Speedat\Domain\Notify;

/**
 * An operational alert: the message, plus the key it is deduplicated by.
 *
 * The key is part of the message rather than a parameter at the call site
 * because the two must not drift. "rates-stale" is sent at most once a day; if
 * one caller spells it "rates_stale" the office gets the same alert twice every
 * day and nobody can see why from the call.
 */
final class AlertMessage
{
    public function __construct(
        /** Deduplication key: lower case, digits, dot, dash, underscore, colon. */
        public readonly string $key,
        public readonly string $subject,
        public readonly string $text,
    ) {
    }
}
