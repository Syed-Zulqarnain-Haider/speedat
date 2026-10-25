<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Config;
use Speedat\Core\HttpError;
use Speedat\Core\Ip;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Password;
use Speedat\Domain\Auth\PasswordPolicy;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\View\Admin\Routes;

/**
 * THE FIRST OWNER, CREATED FROM A BROWSER, ON A HOST WITH NO SHELL.
 *
 * THE PROBLEM THIS SOLVES, measured rather than imagined. The go-live rehearsal
 * installed a correct release into an empty directory and an empty database on
 * a real Apache host, seeded it, opened `/admin`, and could not get in: a fresh
 * install has no admin row, and creating one needed `bin/seed.php` on a command
 * line. **Hostinger's Web plan may have no shell at all** - SSH is Premium and
 * above, and off by default - so on the cheapest plan the site the owner has
 * just paid for and uploaded is a site he cannot sign in to, with no page
 * anywhere saying why.
 *
 * WHY A PAGE AND NOT A phpMyAdmin RECIPE. The alternative in the rehearsal's
 * write-up was a documented SQL statement carrying a password hash the owner
 * produces without a shell. It was rejected for three reasons, in order of how
 * much they cost:
 *
 *   1. THERE IS NOWHERE TO PRODUCE THE HASH. `password_hash()` needs PHP, and
 *      the plan in question is exactly the one with no PHP prompt. The only
 *      honest instruction left is "paste your password into a hashing website",
 *      which is the last sentence any deployment document should contain.
 *   2. A HAND-WRITTEN INSERT IS A HAND-WRITTEN INSERT. One wrong column, one
 *      smart quote out of a word processor, one `role` of `Owner` instead of
 *      `owner`, and the account exists and cannot sign in - with the failure
 *      landing on the sign-in page as "Email or password is wrong", which is
 *      the same sentence a wrong password gets, deliberately (see
 *      `LoginController`). The owner would have no way to tell the two apart.
 *   3. IT TEACHES THE OWNER TO EDIT `admins` BY HAND, which is the one table on
 *      this site where a mistake is an account.
 *
 * `bin/seed.php --password-file=...` driven from an hPanel cron entry is the
 * other no-shell path and it stays; it suits an operator who is already in the
 * Cron Jobs screen. This is the path that needs nothing but the browser.
 *
 * THE FOUR RULES THIS DOOR IS BUILT FROM, and each is a test in
 * `tests/Http/AdminSetupTest.php`:
 *
 *   1. IT ONLY EXISTS ON AN EMPTY `admins` TABLE. The first thing both handlers
 *      do is ask, and the answer to "somebody is already an admin" is
 *      `HttpError::notFound()` - the same 404 a URL that was never a route
 *      gets, because "this page is not here" and "this page is here and shut"
 *      are different amounts of information to hand a stranger. `Admins::anyExist()`
 *      fails CLOSED: a database it cannot read answers "somebody is there".
 *   2. IT REFUSES TO RUN WITHOUT A KEY THE INSTALLER PUT IN `config.php`.
 *      `auth.setup_token`, at least MIN_TOKEN characters, compared with
 *      `Str::secureEquals`. No token in the file means no page at all - so a
 *      release that lands on a host with nobody watching has not opened
 *      anything, and the operator turns this on deliberately, for the ten
 *      minutes he needs it.
 *   3. IT CREATES EXACTLY ONE OWNER, OR NOTHING. The emptiness of the table is
 *      part of the INSERT rather than a question asked before it - see
 *      `Admins::createFirstOwner()` - so two requests arriving together cannot
 *      both succeed.
 *   4. IT BECOMES UNREACHABLE THE MOMENT IT SUCCEEDS, with no cleanup step. The
 *      owner row it just wrote is what shuts it: rule 1 reads the same table.
 *      Removing the `auth.setup_token` line afterwards is good housekeeping and
 *      is NOT what closes the door, because a door closed by an instruction is
 *      a door left open by the first person who does not read one.
 *
 * THE ONE THING IT DOES BESIDES CREATING THE OWNER. If `versions` is empty it
 * publishes the sample document as version 1, exactly as `bin/seed.php` does
 * and under the same guard. Without that the new owner signs in to a 500 - the
 * editor branches its draft from the live version and refuses when there is
 * none, with the message "run the seed first", which is the one instruction the
 * operator this page exists for cannot follow. See
 * publishFirstVersionIfThereIsNone().
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not write `config/config.php` - a
 * web request that can edit the file holding the application key is a remote
 * code execution feature with a friendly name, so when no sign-in method is
 * switched on this page says which line to edit and refuses to go on. It does
 * not send mail, it does not set up two-factor (the new owner does that from
 * Security, with the QR, once he is in), and it does not accept the key in the
 * query string, because a secret in a URL is in the access log of every proxy
 * between the owner and the site.
 */
final class SetupController
{
    /**
     * The URL, held here rather than in `View\Admin\Routes`.
     *
     * Every other admin path is a `Routes` constant, and this one should be too.
     * It is not, because `app/View/**` belongs to another track in this change;
     * `app/routes.php` therefore registers this path from this constant, which
     * keeps the routing table and the controller reading one string rather than
     * two copies of one. Moving it to `Routes` is a two-line change and should
     * happen the next time that file is open.
     */
    public const PATH = '/admin/setup';

    /**
     * How long `auth.setup_token` must be before this page will look at it.
     *
     * 24 characters of the hex the config sample tells the operator to generate
     * is 96 bits, which is not guessable at five attempts per fifteen minutes or
     * at any other rate. The floor is here so that `'setup_token' => 'abc'` -
     * which is what a hurried operator types - is not a door with a lock drawn
     * on it: a token this page refuses to accept is a page that does not exist.
     */
    private const MIN_TOKEN = 24;

    /** Attempts at the key, per address, per window. */
    private const ATTEMPTS = 5;

    private const WINDOW = 900;

    /** The one sentence a wrong key gets. It does not say which part was wrong. */
    private const REFUSED = 'That set-up key is not the one in config/config.php.';

    /** GET /admin/setup */
    public function form(App $app): Response
    {
        $this->gate($app);

        return $this->render($app, '');
    }

    /** POST /admin/setup */
    public function create(App $app): Response
    {
        // THE GATE BEFORE THE TOKEN CHECK, deliberately. "Whatever anyone
        // requests" includes a POST from a stranger with no CSRF cookie: on a
        // site that already has an owner this must be a 404 and must not get as
        // far as telling the caller that its form token was the problem.
        $this->gate($app);

        $app->csrf()->assert($app->request);

        // Per address, and it fails CLOSED when the counter is unreachable -
        // `Core\RateLimit`'s allow-list does not carry this bucket, which is the
        // right direction for a door that only needs to open once.
        RateLimit::enforce('admin_setup', $app->request, self::ATTEMPTS, self::WINDOW);

        $supplied = $app->request->post('setup_key', '', 200);

        if (!Str::secureEquals(self::token(), $supplied)) {
            Log::warn('setup_key_refused', ['ip' => Ip::hash($app->request->ip())]);
            Audit::log('anonymous', 'setup_refused', ['reason' => 'key'], $app->request);

            return $this->render($app, self::REFUSED, 401);
        }

        $method = self::method();

        if ($method === SetupPage::METHOD_NONE) {
            // 409 and not 400: the request is fine, the deployment is not ready
            // for it. Registered, so every server can put it on the wire as
            // itself - see Core\Response::statusLine().
            return $this->render($app, '', 409);
        }

        $email = Str::email($app->request->post('email', '', 190));
        $name  = Str::squish($app->request->post('name', '', 190));

        if ($email === '' || !Str::isEmail($email)) {
            return $this->render($app, 'Enter the email address this owner will sign in with.', 422, $email, $name);
        }

        $hash = null;

        if ($method === SetupPage::METHOD_PASSWORD) {
            $password = $app->request->post('password', '', PasswordPolicy::MAX_LENGTH + 1);
            $again    = $app->request->post('password_again', '', PasswordPolicy::MAX_LENGTH + 1);

            if ($password !== $again) {
                return $this->render($app, 'The two passwords are not the same.', 422, $email, $name);
            }

            // The same policy the Security page enforces, so the password made
            // here is not weaker than one changed later.
            $problems = PasswordPolicy::problems($password, $email);

            if ($problems !== []) {
                return $this->render($app, implode(' ', $problems), 422, $email, $name);
            }

            try {
                $hash = Password::hash($password);
            } catch (AuthError $e) {
                return $this->render($app, $e->publicMessage(), 422, $email, $name);
            }
        }

        if (!Admins::createFirstOwner($email, $name, $hash)) {
            // Either somebody won the race, or the write failed. Both are "this
            // page did not create an owner", and neither is worth two sentences.
            Log::warn('setup_owner_not_created', ['email' => Str::maskEmail($email)]);

            return $this->render(
                $app,
                'That did not create an owner. Reload this page: if somebody else has already created one, '
                . 'this page will be gone and you can sign in.',
                409,
                $email,
                $name,
            );
        }

        $seeded = $this->publishFirstVersionIfThereIsNone();

        Audit::log($email, 'setup_owner_created', [
            'method'       => $method,
            'role'         => AdminUser::OWNER,
            'seedPublished' => $seeded,
        ], $app->request);

        Log::info('setup_owner_created', [
            'email'         => Str::maskEmail($email),
            'method'        => $method,
            'seedPublished' => $seeded,
        ]);

        // 303 to the sign-in, which now has a form on it and an account behind
        // it. There is no "it worked" banner because there is no way to carry
        // one that does not put something a person typed into a URL
        // (`Http\Admin\Notice` says why); the sign-in page working IS the
        // confirmation, and this page being gone is the other half of it.
        return Response::redirect($app->request->basePath . Routes::LOGIN, 303)->noStore();
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Publish the sample document as version 1, and ONLY on an empty table.
     *
     * WITHOUT THIS THE NEW OWNER SIGNS IN TO A 500, which would make the whole
     * page a worse version of the problem it solves. The editor branches its
     * draft from the live version and `Domain\SiteDoc\Repo::getDraft()` throws
     * "No published version exists; run the seed first" when there is none - and
     * "run the seed" is exactly the instruction the operator this page exists
     * for cannot follow. `bin/seed.php` publishes it on the path that has a
     * shell; this is the same step on the path that does not.
     *
     * THE GUARD IS THE WHOLE POINT, and it is `bin/seed.php`'s guard, word for
     * word in intent: the intended order on a real host is schema, then the
     * real rates, then the first owner. If the rates are already in, this sees a
     * version and does nothing. It must never be possible for creating an
     * account to put made-up prices on top of the airline's real ones.
     *
     * The document is published `live: false`, so the site keeps its "sample
     * rates" notice until the owner ticks the box himself.
     *
     * A failure here is logged and not raised. The owner has already been
     * created and that is the irreversible half; an account that exists with an
     * editor that needs one more step is recoverable, and a 500 on the way back
     * from creating it is not.
     */
    private function publishFirstVersionIfThereIsNone(): bool
    {
        try {
            if (Repo::getLatestVersion() !== null) {
                return false;
            }

            Repo::publishVersion(new PublishArgs(
                data: Seed::site(),
                by: 'setup',
                source: 'seed',
                summary: 'Sample rates',
                changeCount: 0,
            ));

            Live::forget();

            return true;
        } catch (\Throwable $e) {
            Log::exception('setup_seed_version_failed', $e);

            return false;
        }
    }

    /**
     * The two conditions under which this page exists at all. Neither is a
     * refusal a caller can tell apart from a URL that was never a route.
     *
     * @throws HttpError always, unless this really is a fresh install with a
     *                   key in its configuration
     */
    private function gate(App $app): void
    {
        if (Admins::anyExist()) {
            // Not logged as a warning: on a live site this is what a search
            // engine, a scanner and an old bookmark all get, every day, and a
            // log line per hit is a log nobody reads.
            throw HttpError::notFound(['reason' => 'setup_closed']);
        }

        if (self::token() === '') {
            Log::warn('setup_page_without_key', ['path' => $app->request->path]);

            throw HttpError::notFound(['reason' => 'setup_no_key']);
        }
    }

    /**
     * The configured key, or "" when there is nothing usable in the file.
     *
     * Trimmed, because a value pasted into `config.php` out of a terminal
     * carries a newline more often than not, and a key that fails because of an
     * invisible character is an hour nobody gets back.
     */
    private static function token(): string
    {
        $token = trim(Config::str('auth.setup_token'));

        return strlen($token) >= self::MIN_TOKEN ? $token : '';
    }

    /** Which sign-in this deployment is configured for. */
    private static function method(): string
    {
        if (Config::bool('auth.allow_password_login', false)) {
            return SetupPage::METHOD_PASSWORD;
        }

        if (Str::squish(Config::str('auth.firebase_project_id')) !== '') {
            return SetupPage::METHOD_GOOGLE;
        }

        return SetupPage::METHOD_NONE;
    }

    private function render(App $app, string $error, int $status = 200, string $email = '', string $name = ''): Response
    {
        $company = Live::site()->data->company;

        $page = new SetupPage(
            companyName: $company->name !== '' ? $company->name : 'Speedat International Courier',
            tagline: $company->tagline !== '' ? $company->tagline : 'Speed Against Time',
            method: self::method(),
            error: $error,
            email: $email,
            name: $name,
            minChars: PasswordPolicy::MIN_LENGTH,
        );

        return $app->page('admin/setup', [
            'page'  => $page,
            'theme' => Screen::theme($app),
        ], $status);
    }
}
