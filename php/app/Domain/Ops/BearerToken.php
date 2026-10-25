<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * `Authorization: Bearer <secret>`, compared the only way a secret may be
 * compared.
 *
 * Three rules, each of which was a real bug somewhere before it was a rule:
 *
 *  1. An UNCONFIGURED token refuses everything. The TypeScript wrote
 *     `if (!token || header !== …)` for the same reason: a deploy that forgot
 *     the secret must close the endpoint, never open it to everyone.
 *  2. The comparison is `hash_equals` over the WHOLE header value, so the
 *     scheme word is compared too and "Bearer" cannot be dropped.
 *  3. Nothing here logs or echoes the supplied value. A 401 body that quotes
 *     the token back is a 401 body that ends up in a support ticket.
 */
final class BearerToken
{
    /** Long enough for any sane secret; a megabyte header is not a login attempt. */
    private const MAX_HEADER = 512;

    public static function matches(string $configured, ?string $authorizationHeader): bool
    {
        if ($configured === '' || $authorizationHeader === null) {
            return false;
        }

        if (strlen($authorizationHeader) > self::MAX_HEADER) {
            return false;
        }

        return hash_equals('Bearer ' . $configured, $authorizationHeader);
    }

    /**
     * The same check for a secret arriving as a query parameter, which is how
     * `/health?token=…` has always been called by the uptime monitor.
     *
     * A query string is written to access logs, so this is only ever used for
     * the health detail - never for cron, which can change data.
     */
    public static function equals(string $configured, ?string $supplied): bool
    {
        if ($configured === '' || $supplied === null || $supplied === '') {
            return false;
        }

        if (strlen($supplied) > self::MAX_HEADER) {
            return false;
        }

        return hash_equals($configured, $supplied);
    }
}
