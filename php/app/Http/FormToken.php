<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\Clock;
use Speedat\Core\Config;

/**
 * The signed render timestamp a public form carries. Port of
 * src/lib/form-token.ts.
 *
 * The page embeds one when it is rendered, the submission returns it, and the
 * handler refuses anything submitted faster than a human could type or older
 * than a working day. That is the whole anti-bot design on the contact form
 * (with the honeypot beside it): no third-party CAPTCHA, nothing to load, and
 * nothing a visitor has to solve.
 *
 * WHY IT IS NOT THE CSRF TOKEN. `Core\Csrf` proves the submission came from a
 * page this site issued to THIS browser; it says nothing about when. This
 * proves WHEN the form was drawn, which is the only signal that separates a
 * script posting 40 messages a minute from a person typing one. Both are
 * checked, and neither replaces the other.
 *
 * WHY THE KEY IS DERIVED. `Config::derivedKey('form')` rather than the
 * application key itself, so this token's signature cannot be used to attack
 * the CSRF signing key or the IP hash - the same reason every other subsystem
 * derives its own.
 *
 * The token is not session-bound on purpose: the contact page is cacheable in
 * principle and a visitor who opens it in two tabs must be able to send from
 * either. Replay is bounded by the 24-hour window and by the per-IP rate limit
 * on the route, and a replayed token proves nothing an attacker did not already
 * have.
 */
final class FormToken
{
    /** Anything faster than this was not typed. Matches form-token.ts `minMs`. */
    public const MIN_AGE_MS = 3000;

    /** A form drawn more than a day ago is stale. Matches form-token.ts `maxMs`. */
    public const MAX_AGE_MS = 86_400_000;

    public const OK       = 'ok';
    public const TOO_FAST = 'too_fast';
    public const EXPIRED  = 'expired';
    public const INVALID  = 'invalid';

    /**
     * Mint one for a form about to be rendered.
     *
     * @param int|null $nowMs override the clock; tests only
     */
    public static function issue(?int $nowMs = null): string
    {
        $ts = (string) ($nowMs ?? Clock::millis());

        return $ts . '.' . self::sign($ts);
    }

    /**
     * What the returned token proves.
     *
     * @return self::OK|self::TOO_FAST|self::EXPIRED|self::INVALID
     */
    public static function check(?string $token, ?int $nowMs = null): string
    {
        // The same expression form-token.ts matches: milliseconds and 32 hex
        // characters, nothing else. A malformed token is never hashed.
        if (!is_string($token) || preg_match('/^(\d{10,16})\.([0-9a-f]{32})$/', $token, $m) !== 1) {
            return self::INVALID;
        }

        // hash_equals, never ===: the number of correct leading bytes must not
        // be measurable by timing the response.
        if (!hash_equals(self::sign($m[1]), $m[2])) {
            return self::INVALID;
        }

        $age = ($nowMs ?? Clock::millis()) - (int) $m[1];

        if ($age < self::MIN_AGE_MS) {
            return self::TOO_FAST;
        }

        return $age > self::MAX_AGE_MS ? self::EXPIRED : self::OK;
    }

    private static function sign(string $timestamp): string
    {
        return substr(hash_hmac('sha256', $timestamp, Config::derivedKey('form')), 0, 32);
    }
}
