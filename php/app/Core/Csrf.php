<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Every state-changing form carries a token; the server refuses the post
 * without it. Without this, any page on the internet can make a signed-in
 * admin's browser publish a price change.
 *
 * The token is `issued-at . nonce . HMAC(issued-at.nonce)`, signed with a key
 * derived from the application key AND the session secret, so:
 *   - it cannot be minted by anyone who does not have the application key;
 *   - it stops working when the session ends;
 *   - it expires on its own, which quietly kills the tab that has been open
 *     since Tuesday.
 *
 * It does NOT stop working when the session id rotates, and it does not depend
 * on whether the session row had already been read when the token was issued.
 * Both of those used to be true and both produced the same symptom: a correct
 * form, correctly filled in, refused with "this form expired". The secret is a
 * property of the session row now - see Session::secret() and
 * Session::rotate().
 *
 * Comparison is hash_equals(), never ===, so the number of correct leading
 * bytes cannot be measured by timing the response.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS TOKEN COVERS, AND WHAT IT DOES NOT
 * ---------------------------------------------------------------------------
 * Written out because an audit had to measure it to find out, and because three
 * of the gaps below read as holes until you know why they are not. Every line
 * here was observed, not assumed (audit round 1, finding 16).
 *
 * IT COVERS: a POST made by another site through the visitor's browser. The
 * attacker's page can make the request but cannot read a token out of ours
 * (same-origin policy) and cannot mint one (the HMAC key is the application key
 * plus the session secret). Visitor A's token does not verify for visitor B.
 *
 * IT DOES NOT COVER, deliberately:
 *
 *   1. REPLAY. The token is not one-time. The same value verifies as many times
 *      as it is presented, for up to MAX_AGE (12 hours) inside one session.
 *      That is correct for a form - a one-time token breaks the back button, two
 *      tabs, and every double-click - and it is NOT enough for an action that
 *      must not happen twice. Publish and restore need their own idempotency
 *      key (the version id they believe they are acting on), not a tighter CSRF
 *      token. Tightening this one would trade a real usability cost for no
 *      protection, because an attacker who can replay a token can also just ask
 *      the page for a fresh one.
 *
 *   2. A MISSING `Origin` HEADER. originOk() allows absence: a few clients and
 *      privacy extensions strip it, and the token is the actual protection, so
 *      refusing on absence locks those people out of the site to catch an
 *      attacker the token catches anyway. The literal string `null` is a
 *      different thing and IS refused - see originOk().
 *
 *   3. ANYTHING A GET DOES. This class is only ever asserted on POST. That is
 *      not a gap as long as no GET route changes state, which is a rule of the
 *      routing layer and not something this class can enforce.
 *
 *   4. AN ATTACKER WHO CAN RUN SCRIPT ON OUR OWN ORIGIN. XSS reads the token
 *      out of the page and then has everything. CSRF tokens never covered that;
 *      the Content-Security-Policy and the output escaping do.
 *
 *   5. AN ATTACKER WHO CAN SET COOKIES ON THIS SITE. For a signed-out visitor
 *      the secret is a cookie the client holds (Session::ANON_COOKIE), so
 *      whoever can set `speedat_ct` can pair a token with a secret they chose.
 *      `SameSite=Lax` closes the cross-site version of that - the planted cookie
 *      is not sent with a cross-site POST, secret() mints a fresh one, and the
 *      token fails - which is why this is depth rather than a hole. What it
 *      still wants is the `__Host-` cookie prefix, so a network attacker cannot
 *      plant it over plain http; that is recorded rather than done, because the
 *      prefix requires `Secure` and this project is developed over http on
 *      localhost, where a `__Host-` cookie is silently discarded by the browser
 *      and every anonymous form would then fail its CSRF check with no visible
 *      cause.
 */
final class Csrf
{
    public const FIELD = '_token';

    /** A form older than this is refused with "reload and try again". */
    private const MAX_AGE = 43200; // 12 hours

    /** Tolerance for a client clock that runs fast. */
    private const FUTURE_SKEW = 60;

    private ?string $issued = null;

    public function __construct(private readonly Session $session)
    {
    }

    /** One token per request, reused by every form on the page. */
    public function token(): string
    {
        if ($this->issued !== null) {
            return $this->issued;
        }

        $issuedAt = (string) Clock::timestamp();
        $nonce    = Str::random(12);

        return $this->issued = $issuedAt . '.' . $nonce . '.' . $this->sign($issuedAt . '.' . $nonce);
    }

    /** The hidden input, ready to drop into a template. */
    public function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    public function check(?string $token): bool
    {
        if (!is_string($token) || preg_match('/^(\d{10,13})\.([A-Za-z0-9_\-]{8,32})\.([a-f0-9]{32})$/', $token, $m) !== 1) {
            return false;
        }

        [$issuedAt, $nonce, $signature] = [$m[1], $m[2], $m[3]];

        if (!hash_equals($this->sign($issuedAt . '.' . $nonce), $signature)) {
            return false;
        }

        $age = Clock::timestamp() - (int) $issuedAt;

        return $age <= self::MAX_AGE && $age >= -self::FUTURE_SKEW;
    }

    /**
     * Check the token that came with this request, throwing the 403 that the
     * front controller renders as "this form expired".
     *
     * It threw a 419 until the go-live rehearsal measured that status reaching
     * the wire as a 500 on real Apache; `Core\HttpError::invalidToken()` carries
     * the measurement and the reason 403 is the right code to replace it with.
     *
     * Both checks run here. originOk() was written as "defence in depth" and
     * then never called from anywhere, which made it defence in nothing; a
     * reviewer was right to call it dead code. It is one line and one string
     * comparison, and it catches the case the token cannot - a token farmed
     * from a page the attacker loaded themselves - so it is wired in here
     * rather than deleted.
     *
     * @throws HttpError
     */
    public function assert(Request $request): void
    {
        if (!$this->originOk($request)) {
            Log::warn('csrf_origin_rejected', [
                'path'   => $request->path,
                'origin' => Str::limit($request->header('origin'), 100),
                'ip'     => Ip::hash($request->ip()),
            ]);

            throw HttpError::invalidToken(['path' => $request->path, 'reason' => 'origin']);
        }

        if (!$this->check($request->post(self::FIELD, '', 200))) {
            Log::warn('csrf_rejected', ['path' => $request->path, 'ip' => Ip::hash($request->ip())]);

            throw HttpError::invalidToken(['path' => $request->path]);
        }
    }

    /**
     * Is this POST's Origin one of ours?
     *
     * A cross-site form submission carries the attacker's Origin, which is the
     * one thing about it the attacker cannot choose. An ABSENT Origin is
     * allowed: a few clients and privacy extensions still strip it, and the
     * token is the actual protection - refusing on absence would lock those
     * people out of the site to catch an attacker who would simply be caught
     * by the token instead.
     *
     * `Origin: null` IS REFUSED, and the difference between the two matters.
     * Absence is a client that did not send the header. The literal string
     * `null` is what a sandboxed iframe, a `data:` document and a few
     * cross-origin redirect chains send - which makes it a value an attacker can
     * arrange, and this method used to treat it as if it were absence. Refusing
     * it costs nothing: no browser sends `null` for a form posted from a page on
     * this site.
     *
     * Both the configured URL and the host this request actually arrived on
     * are accepted. Accepting only `app.url` turns a www/apex mismatch, or a
     * staging copy on another hostname, into "this form expired" on every
     * form - a configuration typo that presents as a broken admin.
     */
    public function originOk(Request $request): bool
    {
        $origin = strtolower(rtrim($request->header('origin'), '/'));
        if ($origin === '') {
            return true;
        }
        if ($origin === 'null') {
            return false;
        }

        $allowed = [strtolower(rtrim($request->origin(), '/'))];

        $configured = rtrim(Config::str('app.url'), '/');
        if ($configured !== '') {
            $allowed[] = strtolower($configured);
        }

        return in_array($origin, $allowed, true);
    }

    private function sign(string $payload): string
    {
        $key = hash_hmac('sha256', $this->session->secret(), Config::derivedKey('csrf'), true);

        return substr(hash_hmac('sha256', $payload, $key), 0, 32);
    }
}
