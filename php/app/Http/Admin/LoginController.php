<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Paths;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Auth\CertificatesUnavailable;
use Speedat\Domain\Auth\FirebaseBrowser;
use Speedat\Domain\Auth\FirebaseToken;
use Speedat\Domain\Auth\FirebaseTokenError;
use Speedat\Domain\Auth\GoogleCertificates;
use Speedat\Domain\SiteDoc\Live;
use Speedat\View\Admin\LoginPage;
use Speedat\View\Admin\Routes;

/**
 * Getting in: Google or a password, then the six-digit code.
 *
 * THE ONE SENTENCE EVERY REFUSAL USES. "Email or password is wrong" is the
 * answer to an address that is not an admin, an address that is an admin with no
 * password set, and a wrong password - and `Domain\Auth\Password::verify()`
 * spends the same time on all three, so neither the words nor the clock says
 * which it was. An admin list is a phishing target; this is the whole reason
 * nothing here is more helpful than that.
 *
 * TWO LIMITS, NOT ONE, AND THEY ARE DIFFERENT SIZES.
 *
 *   per account  `limits.login_per_15min` (10) attempts in fifteen minutes.
 *                This is the one that stops a password being guessed.
 *   per address  four times that. An office of four shares one address and one
 *                person's forgotten password must not lock out the other three;
 *                a single machine working through a word list still hits it.
 *
 * The Google path has its own per-account bucket, `login_google_account`, of
 * the same size (2026-10-26). It used to share `login_account`, so ten wrong
 * passwords typed at the owner's address by anybody also locked the OWNER out
 * of Google sign-in for fifteen minutes - the one way in that the password
 * guesser cannot use. Code guessing is capped separately, per account, by
 * `login_code`, so a second bucket does not buy a code guesser anything.
 *
 * Both fail CLOSED when the counter is unreachable - `Core\RateLimit`'s
 * allow-list does not contain these buckets, deliberately.
 *
 * THE SECOND STEP IS SERVER-SIDE STATE. See `PendingLogin`: the first factor's
 * result never goes back to the browser, the code is spent by an UPDATE that can
 * only succeed once, and "Start again" posts to the sign-out route, which
 * destroys the record rather than leaving it to time out.
 */
final class LoginController
{
    /** Attempts per account per window. The per-address bucket is this times four. */
    private const ACCOUNT_ATTEMPTS = 10;

    /** How many six-digit codes may be tried against one ACCOUNT per window. */
    private const CODE_ATTEMPTS = 6;

    private const WINDOW = 900;

    /**
     * How long ago the Google sign-in itself may have happened, in seconds.
     *
     * A Firebase ID token lives for an hour, and nothing stopped one copied out
     * of a browser (a shared laptop's devtools, a proxy log, a support
     * screenshot) being posted here at minute fifty-nine (2026-10-26). The
     * sign-in page always runs a fresh signInWithPopup with in-memory
     * persistence (public/assets/js/firebase-signin.js) and posts the token
     * seconds later, so a real sign-in is never close to this.
     */
    private const GOOGLE_FRESH = 300;

    /** Clock skew allowed on top of GOOGLE_FRESH - the verifier's own leeway. */
    private const GOOGLE_LEEWAY = 60;

    /** What a 503 from the Google path says. About the server, never the person. */
    private const GOOGLE_UNAVAILABLE = 'The server could not reach Google to check sign-ins. Try again in a few minutes; '
        . 'if it keeps happening, check storage/logs for login_google_unavailable.';

    /** The refusal, worded the same whichever half failed. */
    private const REFUSED = 'Email or password is wrong.';

    /**
     * The Firebase JS SDK build the sign-in page loads when config names none.
     * Pinned rather than "latest": a CDN that moves under a live sign-in page is
     * a sign-in page that breaks on a morning nobody deployed anything.
     */
    private const SDK_VERSION = '10.12.2';

    /** GET /admin/login - or the code step, when a first factor is already in. */
    public function form(App $app): Response
    {
        // Somebody who is already in does not need this page, and a login form
        // rendered under a live session is how a second session gets started by
        // accident.
        if (Guard::admin($app) !== null) {
            return Response::redirect($app->request->basePath . Routes::RATES, 302)->noStore();
        }

        $pending = PendingLogin::current($app);

        return $this->render(
            $app,
            $pending === null ? LoginPage::STEP_CREDENTIALS : LoginPage::STEP_CODE,
            Notice::error($app),
            $pending['email'] ?? $app->request->query('email', '', 190),
        );
    }

    /** POST /admin/login/password */
    public function password(App $app): Response
    {
        $app->csrf()->assert($app->request);

        if (!$this->passwordsEnabled()) {
            // Not a refusal about this account: a fact about the deployment,
            // and one the person in front of the screen can act on.
            return $this->refuse($app, 'Password sign-in is switched off on this deployment. Use Continue with Google.');
        }

        $email    = Str::email($app->request->post('email', '', 190));
        $password = $app->request->post('password', '', 200);

        $this->limitByAddress($app);

        if ($email === '' || !Str::isEmail($email) || $password === '') {
            return $this->refuse($app, self::REFUSED, $email);
        }

        RateLimit::enforceKey('login_account', $email, $this->accountAttempts(), self::WINDOW);

        $row = Admins::row($email);

        // The verify runs even when there is no row, so that "not an admin" and
        // "wrong password" cost the same time. Admins::passwordMatches() would
        // skip it, so the decoy is spent here instead.
        if ($row === null) {
            Admins::passwordMatches(['email' => $email, 'password_hash' => null], $password);
            Log::warn('login_refused', ['reason' => 'unknown', 'email' => Str::maskEmail($email)]);

            return $this->refuse($app, self::REFUSED, $email);
        }

        if (!Admins::passwordMatches($row, $password)) {
            Audit::log($email, 'login_refused', ['method' => PendingLogin::BY_PASSWORD], $app->request);
            Log::warn('login_refused', ['reason' => 'password', 'email' => Str::maskEmail($email)]);

            return $this->refuse($app, self::REFUSED, $email);
        }

        return $this->firstFactorPassed($app, $row, PendingLogin::BY_PASSWORD);
    }

    /**
     * POST /admin/login/google.
     *
     * The browser gets an ID token from Firebase and posts it here; this verifies
     * it against Google's published certificates (no service account, no
     * Composer - `Domain\Auth\FirebaseToken`), and only then asks the `admins`
     * table whether that address may sign in. A token signed by anybody else,
     * for another project, for an unverified address, through any provider but
     * Google, or for a sign-in more than five minutes old never reaches the
     * table. When the certificates cannot be had at all the answer is a 503
     * about the server, not a refusal (2026-10-26).
     *
     * WITH JAVASCRIPT OFF there is no token to post, because only the browser
     * can get one from Google. The button still submits, and the answer says so
     * and - where password sign-in is on - points at the password form, which
     * does work without JavaScript.
     */
    public function google(App $app): Response
    {
        $app->csrf()->assert($app->request);

        $this->limitByAddress($app);

        $project = Str::squish(Config::str('auth.firebase_project_id'));

        if ($project === '') {
            return $this->refuse($app, 'Google sign-in is not configured on this deployment.');
        }

        $idToken = $this->idToken($app);

        if ($idToken === '') {
            return $this->refuse(
                $app,
                'Continue with Google needs JavaScript in this browser.'
                . ($this->passwordsEnabled() ? ' Sign in with your email and password below.' : ' Turn it on and try again.'),
            );
        }

        $certs = null;

        try {
            // The app's own public/ is named as a web root because on Hostinger
            // it is NOT the DOCUMENT_ROOT (public_html is), and a cache under
            // either would be served by somebody's misconfigured vhost.
            $clock    = static fn (): int => Clock::timestamp();
            $certs    = new GoogleCertificates(Paths::cache() . '/google-certs', clock: $clock, webRoots: [Paths::publicDir()]);
            $verifier = new FirebaseToken($project, $certs, self::GOOGLE_LEEWAY, $clock);
            $identity = $verifier->verify($idToken);
        } catch (CertificatesUnavailable $e) {
            // "We could not check", not "you were refused" (2026-10-26). Caught
            // before FirebaseTokenError, which it extends: the owner used to
            // get the forged-token sentence during a Google outage and on a
            // host that blocks outbound HTTPS, and retried into the same wall.
            // Thrown before any claim is read, so it says nothing about who is
            // an admin.
            Log::error('login_google_unavailable', ['reason' => $e->getMessage()]);

            return $this->render($app, LoginPage::STEP_CREDENTIALS, self::GOOGLE_UNAVAILABLE, '', 503)
                ->header('Retry-After', '60');
        } catch (FirebaseTokenError $e) {
            Log::warn('login_google_refused', ['reason' => $e->getMessage()]);

            return $this->refuse($app, $this->googleRefused());
        } finally {
            // Sign-in works without the disk cache, but every request becomes
            // a round trip to Google - which used to be silent.
            if ($certs !== null && $certs->cacheWriteFailed()) {
                Log::warn('cert_cache_unwritable', ['dir' => Paths::cache() . '/google-certs']);
            }
        }

        // GOOGLE AND ONLY GOOGLE (2026-10-26). The token proves Firebase signed
        // somebody in, not how: a project with Email/Password or email-link
        // turned on in the console - by a teammate, for another app sharing
        // the project - mints tokens with email_verified true that never went
        // near Google's account checks. This page offers Google alone, so
        // anything else is refused, worded like any other refusal.
        if ($identity->signInProvider !== 'google.com') {
            Log::warn('login_google_refused', ['reason' => 'provider', 'provider' => Str::limit($identity->signInProvider, 40)]);

            return $this->refuse($app, $this->googleRefused());
        }

        // A FRESH SIGN-IN, NOT A TOKEN THAT WAS LYING AROUND. See GOOGLE_FRESH.
        // auth_time is required by the verifier, so this cannot be skipped by
        // leaving the claim out.
        $age = Clock::timestamp() - $identity->authTime;

        if ($age > self::GOOGLE_FRESH + self::GOOGLE_LEEWAY) {
            Log::warn('login_google_refused', ['reason' => 'stale', 'ageSeconds' => $age]);

            return $this->refuse($app, $this->googleRefused());
        }

        $verified = $identity->verifiedEmail();

        if ($verified === null) {
            Log::warn('login_google_refused', ['reason' => 'unverified_email']);

            return $this->refuse($app, $this->googleRefused());
        }

        $email = Str::email($verified);

        // The per-account cap the password path has had all along, in a bucket
        // of its own (see the class docblock). Without it the Google path was
        // capped only per address (forty in fifteen minutes), and every pass
        // through here mints a fresh pending login. Counted only once Google
        // has vouched for the address: an unverified token must not be able to
        // spend the owner's allowance.
        if ($email !== '') {
            RateLimit::enforceKey('login_google_account', $email, $this->accountAttempts(), self::WINDOW);
        }

        $row = $email === '' ? null : Admins::row($email);

        if ($row === null) {
            // Worded exactly as a bad token is, so a stranger with a valid
            // Google account cannot learn whether that address is an admin.
            Log::warn('login_google_refused', ['reason' => 'not_an_admin', 'email' => Str::maskEmail($email)]);

            return $this->refuse($app, $this->googleRefused());
        }

        return $this->firstFactorPassed($app, $row, PendingLogin::BY_GOOGLE);
    }

    /** POST /admin/login/code - the second factor. */
    public function code(App $app): Response
    {
        $app->csrf()->assert($app->request);

        $pending = PendingLogin::current($app);

        if ($pending === null) {
            return $this->toLogin($app, ['err' => 'expired']);
        }

        // Per ACCOUNT, not per pending login and not per address. This bucket
        // exists to stop a million guesses at six digits, and it used to be
        // keyed on the pending record - but a fresh record is minted on every
        // first-factor success, so redoing the password reset the count: about
        // sixty codes per fifteen minutes with the password, two hundred and
        // forty through Google, roughly a 40% chance of a correct code within a
        // month and no lockout anywhere (review, 2026-10-23). The secret being
        // guessed belongs to the account, so the account is what is counted.
        RateLimit::enforceKey('login_code', (string) $pending['email'], self::CODE_ATTEMPTS, self::WINDOW);

        $typed = $app->request->post('code', '', 12);

        if ($typed === '') {
            return $this->toLogin($app, ['err' => 'nocode']);
        }

        $row = Admins::row($pending['email']);

        if ($row === null || (int) ($row['totp_enabled'] ?? 0) !== 1) {
            // The account was changed underneath a half-finished sign-in.
            PendingLogin::abandon($app);

            return $this->toLogin($app, ['err' => 'expired']);
        }

        if (!Admins::verifyCode($row, $typed)) {
            Audit::log($pending['email'], 'login_code_refused', ['method' => $pending['method']], $app->request);
            Log::warn('login_code_refused', ['email' => Str::maskEmail($pending['email'])]);

            return $this->toLogin($app, ['err' => 'code']);
        }

        PendingLogin::abandon($app);

        return $this->signIn($app, (string) $row['email'], $pending['method'], true);
    }

    /**
     * POST /admin/logout - and "Start again" on the code screen.
     *
     * Deliberately NOT behind `Guard::write()`: the code screen has no session
     * yet, and its way out has to work. The CSRF token is still required, so
     * nothing on the internet can sign the owner out by loading an image.
     */
    public function logout(App $app): Response
    {
        $app->csrf()->assert($app->request);

        $email = $app->session()->email();

        PendingLogin::abandon($app);

        if ($email !== null) {
            Audit::log($email, 'logout', [], $app->request);
        }

        $app->session()->destroy();

        return $this->toLogin($app, []);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The first factor is in. Either the account needs a code, or it does not
     * and this is a sign-in.
     *
     * @param array<string,mixed> $row
     */
    private function firstFactorPassed(App $app, array $row, string $method): Response
    {
        $email = (string) ($row['email'] ?? '');

        if ((int) ($row['totp_enabled'] ?? 0) === 1) {
            PendingLogin::begin($app, $email, $method);

            Audit::log($email, 'login_first_factor', ['method' => $method, 'awaiting' => 'totp'], $app->request);

            return Response::redirect($app->request->basePath . Routes::LOGIN, 303)->noStore();
        }

        return $this->signIn($app, $email, $method, false);
    }

    /** Start the session and go to the editor. */
    private function signIn(App $app, string $email, string $method, bool $withCode): Response
    {
        // Session::start() deletes any previous row for this browser and mints a
        // new token, so the id a visitor arrived with is never the id they leave
        // signed in with - which is the whole of session fixation.
        $app->session()->start($email);

        Audit::log($email, 'login', ['method' => $method, 'secondFactor' => $withCode], $app->request);

        return Response::redirect($app->request->basePath . Routes::RATES, 303)->noStore();
    }

    /**
     * The Firebase values the BROWSER needs, as JSON ready to embed - or '' when
     * the deployment has not been given all of them.
     *
     * NONE OF THESE IS A SECRET. They identify the project to Google's auth
     * endpoint and are printed into the page for anybody to read; Firebase's own
     * quickstart pastes them into the HTML. They come from config anyway so that
     * the staging copy cannot sign somebody into the live project.
     *
     * ALL FOUR OR NOTHING. A page with three of them renders a button that
     * cannot reach Google, and the person in front of it has no way to tell that
     * from a Google outage. Missing one is a configuration mistake with a
     * documented fix (docs/firebase-setup.md), so the button simply does not
     * appear. An auth domain that is not a bare hostname counts as missing
     * (2026-10-26): it is also a frame-src source, and a button whose popup
     * the page's own CSP would block is the same dead button.
     *
     * Public and static because that is what makes it testable without a
     * request, a session or a database - the visibility rule is the thing worth
     * a test, and it lives here.
     */
    public static function firebaseConfigJson(): string
    {
        // The rule itself lives in FirebaseBrowser (2026-10-26), because Core\App
        // asks the same question to decide the login page's CSP, and the
        // button and the policy that lets it work must never disagree.
        $config = FirebaseBrowser::config();

        if ($config === null) {
            return '';
        }

        $config['sdkVersion'] = Str::squish(Config::str('auth.firebase_sdk_version', self::SDK_VERSION));

        if ($config['sdkVersion'] === '' || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $config['sdkVersion']) !== 1) {
            // A version is part of a URL this page tells the browser to execute.
            // Anything that is not three numbers is not a version, and guessing
            // what was meant would be guessing at a script source.
            $config['sdkVersion'] = self::SDK_VERSION;
        }

        $json = json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json === false ? '' : $json;
    }

    /** The ID token, from a form post or from a JSON body. */
    private function idToken(App $app): string
    {
        $fromForm = $app->request->post('idToken', '', 8192);

        if ($fromForm !== '') {
            return $fromForm;
        }

        if (!$app->request->wantsJson()) {
            return '';
        }

        $body  = $app->request->json(16384);
        $token = $body['idToken'] ?? $body['credential'] ?? '';

        return is_string($token) ? Str::limit(trim($token), 8192) : '';
    }

    /** Per-address limit: four accounts' worth, for one office behind one address. */
    private function limitByAddress(App $app): void
    {
        RateLimit::enforce('login', $app->request, $this->accountAttempts() * 4, self::WINDOW);
    }

    private function passwordsEnabled(): bool
    {
        return Config::bool('auth.allow_password_login', false);
    }

    /**
     * The one Google refusal, worded the same for a bad token and for a
     * stranger's good one. It points at the password form only when there is
     * one: a Google-only deployment that says "or use your email and password"
     * sends the owner to a form that does not exist.
     */
    private function googleRefused(): string
    {
        return 'That Google sign-in was refused. '
            . ($this->passwordsEnabled() ? 'Try again, or use your email and password.' : 'Try again.');
    }

    private function accountAttempts(): int
    {
        return max(3, Config::int('limits.login_per_15min', self::ACCOUNT_ATTEMPTS));
    }

    /**
     * Re-render the sign-in with a message.
     *
     * A refusal is rendered rather than redirected, with a 401: the message
     * belongs to this attempt, the browser must not be able to reload its way
     * back into it, and the status is what a monitor counts.
     */
    private function refuse(App $app, string $message, string $email = ''): Response
    {
        return $this->render($app, LoginPage::STEP_CREDENTIALS, $message, $email, 401);
    }

    /** @param array<string,int|string|bool> $codes */
    private function toLogin(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::LOGIN, $codes), 303)->noStore();
    }

    private function render(App $app, string $step, string $error, string $email = '', int $status = 200): Response
    {
        $doc     = Live::site();
        $company = $doc->data->company;

        $googleReady = Str::squish(Config::str('auth.firebase_project_id')) !== '';
        $passwords   = $this->passwordsEnabled();

        // THE TWO SENTENCES THAT USED TO END AN INSTALL.
        //
        // The go-live rehearsal reached this page on a correctly installed
        // release and found a warning that was true and useless: it said
        // sign-in was not configured, and did not say which file, which line or
        // what to do next. An installer with no shell then has a working website
        // he cannot get into and nothing anywhere to read. Both branches now
        // name the file and the key, and the second names the page that exists
        // for exactly this moment.
        $warning = '';
        if (!$googleReady && !$passwords) {
            $warning = 'Sign-in is not configured on this deployment yet. In config/config.php, either set '
                . 'auth.firebase_project_id to your Firebase project, or set auth.allow_password_login to true. '
                . 'Then reload this page.';
        } elseif (Admins::count() === 0) {
            $warning = 'Nobody has been added as an admin yet, so nobody can sign in. Put an auth.setup_token line '
                . 'in config/config.php - 24 characters or more, of your choosing - then open '
                . SetupController::PATH . ' and create the first owner from this browser. (The other way is '
                . 'bin/seed.php --owner=<email>, which needs a command line or an hPanel cron entry.)';
        }

        $page = new LoginPage(
            companyName: $company->name !== '' ? $company->name : 'Speedat International Courier',
            tagline: $company->tagline !== '' ? $company->tagline : 'Speed Against Time',
            step: $step,
            error: $error,
            email: $step === LoginPage::STEP_CREDENTIALS ? $email : '',
            googleReady: $googleReady,
            configWarning: $warning,
            passwordsEnabled: $passwords,
        );

        // allowFirebaseAuth(): this page, and only this page, gets the Firebase
        // SDK's sources in its CSP - see Core\App::applySecurityHeaders().
        return $app->page('admin/login', [
            'page'     => $page,
            'firebase' => self::firebaseConfigJson(),
            'theme'    => $app->request->cookie('theme') === 'dark' ? 'dark' : 'light',
        ], $status)->allowFirebaseAuth();
    }
}
