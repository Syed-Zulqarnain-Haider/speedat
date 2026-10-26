<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * The container, and the only shared object in the application. Everything a
 * handler needs hangs off it, built on first use:
 *
 *     $app->session()->email()
 *     $app->csrf()->assert($app->request)
 *     $app->view()->render('pages/home')
 *
 * It is also where the response gets its security headers, so that they are
 * applied in one place, to every response, including the error pages - a
 * header added per-handler is a header that will be missing from the handler
 * somebody writes next month.
 *
 * tests/Core/AppTest.php pins the two decisions that matter: that the CSP nonce
 * is minted once per request and is the value the policy names (one per read
 * would name one nonce in the header and print another in the page, blocking
 * every inline script on the site), and that the headers reach EVERY response
 * rather than only the ones a handler remembered to ask about.
 */
final class App
{
    public readonly Cookies $cookies;

    /** Per-request CSP nonce. Inline <script> tags carry it; injected ones cannot. */
    public readonly string $nonce;

    private ?Session $session = null;

    private ?Csrf $csrf = null;

    private ?View $view = null;

    private ?Mail $mail = null;

    public function __construct(public readonly Request $request)
    {
        $this->cookies = Cookies::forRequest($request);
        $this->nonce   = Str::random(16);
    }

    public function session(): Session
    {
        return $this->session ??= new Session($this->request, $this->cookies);
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf($this->session());
    }

    public function mail(): Mail
    {
        return $this->mail ??= Mail::fromConfig();
    }

    public function view(): View
    {
        if ($this->view === null) {
            $this->view = new View(Paths::views(), $this->nonce);
            $this->view->share([
                'basePath' => $this->request->basePath,
                'csrf'     => $this->csrf(),
                'appName'  => 'Speedat International',
                'appUrl'   => Config::str('app.url'),
                'env'      => Config::env(),
                'year'     => Clock::now()->format('Y'),
            ]);
        }

        return $this->view;
    }

    /** Render a template into an HTML response that is never cached. @param array<string,mixed> $data */
    public function page(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view()->render($template, $data), $status)->noStore();
    }

    /** Apply the headers, write the cookies, send the body. Called once per request. */
    public function send(Response $response): void
    {
        // PHP's own `X-Powered-By: PHP/8.2.x`. expose_php would stop it at the
        // source, but it is PHP_INI_SYSTEM - neither .htaccess nor .user.ini can
        // set it, only the host's php.ini - and on Apache + mod_php the
        // .htaccess `Header always unset` never reached it either (measured on
        // the sim, 2026-10-26). So it is removed here, for every response the
        // application sends, whatever the host does with .htaccess. Behind the
        // same headers_sent() guard Response::send() uses: once output has
        // started the banner is already on the wire, and header_remove() would
        // only add a warning to a response that can no longer be changed.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
        $this->applySecurityHeaders($response);
        $this->cookies->flush();
        $response->send(!$this->request->isHead());
    }

    /**
     * The headers every response carries.
     *
     * .htaccess sets most of these too, because Apache can and a PHP fatal
     * before this point would otherwise send a bare page. They are set here as
     * well because .htaccess is not guaranteed to be honoured on every host,
     * and because the CSP nonce changes per request and Apache cannot produce
     * one.
     *
     * Where .htaccess IS honoured, its copy is the one that goes out and this
     * one is removed, so each header is sent once: LiteSpeed replaces PHP's
     * value by itself, and on Apache public/.htaccess unsets it (PHP's
     * header() and `Header always set` land in different tables there, and a
     * doubled Cross-Origin-Opener-Policy is an invalid one). That only works
     * because the values below are spelled exactly as the .htaccess spells
     * them - tests/Security/HeaderParityTest holds all five.
     */
    private function applySecurityHeaders(Response $response): void
    {
        $nonce = $this->nonce;

        $directives = [
            "default-src 'self'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            // Scripts must carry this request's nonce. An injected <script> has
            // no way to guess it, which is the whole point.
            "script-src 'self' 'nonce-" . $nonce . "'" . self::extra('security.csp_script'),
            // Styles are 'unsafe-inline' deliberately: the v3 design uses style
            // attributes, and a nonce on style-src would silently disable them
            // (a CSP nonce makes 'unsafe-inline' be ignored). Inline CSS is a
            // far smaller risk than inline script, and the script rule above is
            // the one that stops an injection running.
            "style-src 'self' 'unsafe-inline'",
            "connect-src 'self'" . self::extra('security.csp_connect'),
            // Who this page may frame. 'self' by default - the public site
            // frames nothing. Google sign-in is the one exception: Firebase's
            // popup flow loads an iframe from the project's auth domain and
            // accounts.google.com, so an admin who turns Google sign-in on adds
            // those origins through security.csp_frame (docs/firebase-setup.md).
            // Empty config leaves it at 'self', so nothing changes until it is
            // deliberately widened - the same shape as csp_script/csp_connect.
            "frame-src 'self'" . self::extra('security.csp_frame'),
        ];

        if ($this->request->https) {
            $directives[] = 'upgrade-insecure-requests';
        }

        $response->header('Content-Security-Policy', implode('; ', $directives));
        $response->header('X-Content-Type-Options', 'nosniff');
        // DENY, not SAMEORIGIN. `frame-ancestors 'none'` a few lines up already
        // says exactly this to every browser that reads a CSP, and two headers
        // on the same response disagreeing about the same question is how a
        // reviewer stops believing either of them - docs/quality-bar.md §2 asks
        // for DENY and got SAMEORIGIN for no stated reason. Nothing on this site
        // is framed by anything: the admin is full-page, the calculator is
        // inline, there is no embed and no payment iframe. So a browser too old
        // to read the CSP now gets the same answer a current one gets.
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        // same-origin-allow-popups, NOT same-origin. Google sign-in is
        // Firebase's signInWithPopup (public/assets/js/firebase-signin.js),
        // and under plain same-origin the cross-origin auth popup lands in a
        // separate browsing context group: the opener reads popup.closed as
        // true while the user is still in Google's window, Firebase rejects
        // with popup-closed-by-user a few seconds later, and every retry fails
        // the same way. The Next.js build sent allow-popups for exactly this
        // reason. What we keep is the half that matters - a cross-origin page
        // that opens THIS site still gets no handle on it. Same value in both
        // .htaccess copies, because there they win (see below).
        $response->header('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        // ONE value, spelled identically in public/.htaccess and
        // deploy/htaccess/public_html.htaccess. On Hostinger the .htaccess
        // `Header always set` runs after PHP and WINS - measured 2026-09-30,
        // where the live Permissions-Policy was the .htaccess string and not
        // this one - so a token missing from either copy is a token nobody
        // denies. The three files had drifted to three different fifth tokens
        // (browsing-topics here, interest-cohort in .htaccess, usb in the
        // deploy copy); this is the union of all of them, so the policy is the
        // same and complete whichever layer answers. tests/Security/
        // HeaderParityTest fails if they drift again.
        $response->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), browsing-topics=(), interest-cohort=()');

        // HSTS is opt-in and only over https. A browser remembers it for a
        // year: setting it before the PHP site is the live https site would
        // lock visitors out of a domain that cannot yet serve them.
        if ($this->request->https && Config::bool('security.hsts', false)) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    /** Extra sources a deployment needs (Firebase, for instance), from config. */
    private static function extra(string $key): string
    {
        $value = Str::squish(Config::str($key));

        // Only scheme-ish source expressions; never a quoted keyword smuggled
        // in from config, and never a semicolon that would start a directive.
        if ($value === '' || preg_match("#^[A-Za-z0-9:/\\.\\-\\*\\s]+$#", $value) !== 1) {
            return '';
        }

        return ' ' . $value;
    }
}
