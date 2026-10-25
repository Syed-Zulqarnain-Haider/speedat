<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Config;

/**
 * A one-shot message that survives exactly one redirect.
 *
 * It exists for post/redirect/get: the contact form stores a lead on POST and
 * then redirects, so that a reload of the thank-you page is a GET and cannot
 * send the message twice. The GET has to know that it is the page after a
 * successful post, and the POST has nowhere to put that except in the response.
 *
 * WHY A SIGNED COOKIE AND NOT THE SESSION. A visitor who has never signed in
 * has no session row - `Core\Session` deliberately keeps anonymous visitors out
 * of the sessions table (see `Session::secret()`), and giving every visitor a
 * row so that one word could be carried across one redirect is a table full of
 * nothing. The cookie is HttpOnly, SameSite=Lax and Secure on https, like every
 * cookie this application sets, and it is deleted the moment it is read.
 *
 * WHY IT IS SIGNED AT ALL. Not to protect a secret - the name is "contact_sent"
 * and there is nothing in it - but so the only thing that can put the site into
 * its "thank you" state is the site. An unsigned cookie would let any page on
 * the internet show a visitor a thank-you for a message that was never sent.
 *
 * It carries a name, not a payload: anything that needs data belongs in the
 * database, and a cookie is not a place to keep a customer's message.
 */
final class Flash
{
    public const COOKIE = 'speedat_flash';

    /** Long enough for a redirect on a slow phone, short enough to be meaningless later. */
    private const TTL = 300;

    /** The only names this application flashes. An unknown name is never written or read. */
    private const NAMES = ['contact_sent'];

    /** Queue the flash on the response that redirects. */
    public static function put(App $app, string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            return;
        }

        $expires = Clock::timestamp() + self::TTL;

        $app->cookies->set(self::COOKIE, $expires . '.' . $name . '.' . self::sign($expires, $name), self::TTL);
    }

    /**
     * Is this the request after that flash? Reading also clears it, so a reload
     * of the thank-you page is an ordinary contact page again.
     */
    public static function take(App $app, string $name): bool
    {
        $raw = $app->request->cookie(self::COOKIE);
        if ($raw === null || $raw === '') {
            return false;
        }

        // Whatever it said, it has been used now.
        $app->cookies->delete(self::COOKIE);

        if (preg_match('/^(\d{10,12})\.([a-z_]{1,32})\.([a-f0-9]{32})$/', $raw, $m) !== 1) {
            return false;
        }

        [$expires, $found, $signature] = [(int) $m[1], $m[2], $m[3]];

        if (!hash_equals(self::sign($expires, $found), $signature)) {
            return false;
        }

        // The cookie's own Max-Age should have done this; a cookie jar the
        // visitor edited by hand does not get to keep it alive.
        return $expires >= Clock::timestamp() && $found === $name;
    }

    private static function sign(int $expires, string $name): string
    {
        return substr(hash_hmac('sha256', $expires . '.' . $name, Config::derivedKey('flash')), 0, 32);
    }
}
