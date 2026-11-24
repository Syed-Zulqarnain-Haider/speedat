<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Config;
use Speedat\Core\HttpError;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\View\Admin\Routes;

/**
 * The door every admin route is behind, and the only file that decides who may
 * open it.
 *
 * `app/routes.php` registers admin handlers THROUGH this class - `Guard::page()`
 * for a screen, `Guard::write()` for a form post, `Guard::owner()` for the three
 * things only an owner may do - so the routing table reads as a list of what is
 * protected and how, and a route added without a wrapper is a route that does
 * not compile (the handler signature takes an AdminUser it can only be given
 * here).
 *
 * FOUR RULES, IN THIS ORDER, ON EVERY WRITE:
 *
 *   1. SESSION. `Core\Session` says which address this browser holds a session
 *      for; `Admins` says whether that address may still sign in. Both, every
 *      request: a session minted before somebody was removed from the table is
 *      not an admin any more.
 *   2. ROLE. Every wrapper names the roles it lets through, and the default is
 *      the back office (`AdminUser::STAFF`) - so a MARKETER IS REFUSED UNLESS A
 *      ROUTE SAYS OTHERWISE, and a screen added next month is closed to them
 *      because nobody remembered. Hiding a button is a courtesy; this is the
 *      control.
 *   3. CSRF. `Core\Csrf::assert()`, which also checks the Origin. Without it,
 *      any page on the internet can make a signed-in owner's browser publish a
 *      price change.
 *   4. RATE LIMIT. Per admin, per minute. Not for brute force - they are already
 *      signed in - but because a stuck autosave loop or a held-down Enter key
 *      should cost a 429 rather than a thousand writes.
 *
 * AN ANONYMOUS REQUEST IS ALWAYS A REDIRECT TO THE SIGN-IN, NEVER A 403. A 403
 * says "this exists and you may not have it", which tells a stranger which admin
 * URLs are real; a 302 to the login is what a browser needs and says nothing.
 * The one thing that must not happen is a 200 with a page in it, which is what
 * the browser harness's first admin check looks for.
 */
final class Guard
{
    /** Writes per admin per minute, unless `limits.admin_write_per_min` says otherwise. */
    private const WRITE_LIMIT = 60;

    /**
     * The signed-in admin, or null.
     *
     * A session whose address is not in `admins` is destroyed on the spot: it
     * cannot become valid again by waiting, and leaving it alive would mean
     * asking the database the same losing question on every request.
     */
    public static function admin(App $app): ?AdminUser
    {
        $email = $app->session()->email();

        if ($email === null) {
            return null;
        }

        $admin = Admins::find($email);

        if ($admin === null) {
            Log::warn('admin_session_without_row', ['email' => Str::maskEmail($email)]);
            $app->session()->destroy();

            return null;
        }

        return $admin;
    }

    /**
     * Wrap a GET handler.
     *
     * @param  callable(App, AdminUser, array<string,string>): Response $handler
     * @param  list<string>                                             $roles   who may open it
     * @return callable(App, array<string,string>): Response
     */
    public static function page(callable $handler, array $roles = AdminUser::STAFF): callable
    {
        return static function (App $app, array $params = []) use ($handler, $roles): Response {
            $admin = self::admin($app);

            if ($admin === null) {
                return self::toLogin($app);
            }

            // `/admin` is where every sign-in lands and what the logo links to.
            // For a marketer that is not a screen, so it is a way to theirs.
            if ($admin->isMarketer() && $app->request->path === Routes::RATES) {
                return Response::redirect($app->request->basePath . Routes::PAGES, 302)->noStore();
            }

            self::assertRole($app, $admin, $roles);

            return $handler($app, $admin, $params);
        };
    }

    /**
     * Wrap a POST handler: session, role, a body that arrived, CSRF, per-admin
     * rate limit.
     *
     * @param  callable(App, AdminUser, array<string,string>): Response $handler
     * @param  list<string>                                             $roles   who may do it
     * @return callable(App, array<string,string>): Response
     */
    public static function write(callable $handler, array $roles = AdminUser::STAFF): callable
    {
        return static function (App $app, array $params = []) use ($handler, $roles): Response {
            $admin = self::admin($app);

            if ($admin === null) {
                return self::toLogin($app);
            }

            self::assertRole($app, $admin, $roles);

            // A BODY PHP THREW AWAY IS A BAD REQUEST, AND IT IS SAID HERE
            // RATHER THAN BY THE CHECK BELOW.
            //
            // Past `post_max_size` PHP discards the whole body: $_POST is empty,
            // $_FILES is empty, and no error code is raised. So the CSRF check
            // on the next line has no token to find and answers "this form
            // expired", which sends the owner to reload a page that was never
            // the problem - the rate card is simply bigger than one request.
            //
            // 400 is the quality bar's answer to an input cap ("input caps make
            // a giant payload a 400") and the same one `Http\ContactController`
            // gives, so the site has ONE story about a body it cannot read.
            // Nothing is written and no write limit is spent: there is nothing
            // to protect here, because there is nothing to read.
            if ($app->request->postWasDiscarded()) {
                Log::warn('admin_body_discarded', [
                    'email'  => Str::maskEmail($admin->email),
                    'path'   => $app->request->path,
                    'length' => Str::limit($app->request->header('content-length'), 20),
                ]);

                throw HttpError::badRequest(
                    'That was too large for the server to accept in one request, so nothing was received '
                    . 'and nothing was changed. Make the change in two halves and try again.'
                );
            }

            $app->csrf()->assert($app->request);

            RateLimit::enforceKey(
                'admin_write',
                $admin->email,
                max(10, Config::int('limits.admin_write_per_min', self::WRITE_LIMIT)),
                60,
            );

            return $handler($app, $admin, $params);
        };
    }

    /**
     * Wrap a POST handler that only an owner may reach: publishing, resuming
     * prices, and anything that manages who can sign in.
     *
     * The refusal is a 403 and not a redirect, deliberately - the caller IS
     * signed in, knows the page exists, and needs to be told that the answer is
     * no rather than sent round a loop to the login screen.
     *
     * @param  callable(App, AdminUser, array<string,string>): Response $handler
     * @return callable(App, array<string,string>): Response
     */
    public static function owner(callable $handler): callable
    {
        return self::write(static function (App $app, AdminUser $admin, array $params) use ($handler): Response {
            if (!$admin->isOwner()) {
                Log::warn('admin_owner_action_refused', [
                    'email' => Str::maskEmail($admin->email),
                    'path'  => $app->request->path,
                ]);

                throw HttpError::forbidden('Only an owner can do that.');
            }

            return $handler($app, $admin, $params);
        });
    }

    /** @param list<string> $roles */
    private static function assertRole(App $app, AdminUser $admin, array $roles): void
    {
        if (in_array($admin->role, $roles, true)) {
            return;
        }

        Log::warn('admin_role_refused', [
            'email' => Str::maskEmail($admin->email),
            'role'  => $admin->role,
            'path'  => $app->request->path,
        ]);

        throw HttpError::forbidden(
            $admin->isMarketer() ? 'Your login can only change the website text.' : 'Only an owner can do that.'
        );
    }

    /** Where an anonymous caller is sent. 302, so the browser comes back with a GET. */
    public static function toLogin(App $app): Response
    {
        return Response::redirect($app->request->basePath . Routes::LOGIN, 302)->noStore();
    }
}
