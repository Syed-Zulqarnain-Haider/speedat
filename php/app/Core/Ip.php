<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Addresses are stored hashed, never raw.
 *
 * An IP address is personal data and a rate-limit table is not a place that
 * needs it: the limiter only ever asks "is this the same visitor as a moment
 * ago", which a keyed hash answers. The key is derived from the application
 * key, so the hashes are stable for as long as the key is and meaningless to
 * anyone who copies the table without it.
 *
 * This is the port of ipHash() in src/lib/limits.ts, including the 32-character
 * truncation - collisions at that length are irrelevant for counting, and the
 * shorter value keeps the index small.
 */
final class Ip
{
    public static function hash(string $ip): string
    {
        $salt = Config::loaded() ? Config::derivedKey('ip-hash') : 'speedat';

        return substr(hash_hmac('sha256', $ip, $salt), 0, 32);
    }
}
