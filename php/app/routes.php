<?php

declare(strict_types=1);

/**
 * The routing table.
 *
 * Every URL the site answers is listed here, in one file, so "what is
 * reachable from the internet" is a question with a written answer rather than
 * a search. Handlers take the container and the matched path parameters and
 * return a Response; the work is in `app/Http/**`, and a handler that grows a
 * body belongs there.
 *
 * WHAT IS NOT IN HERE, AND WHY
 *
 *   A THEME ROUTE. The toggle is `public/assets/js/ui.js`, which writes the
 *   `theme` cookie itself (`document.cookie = "theme=…; Path=/; Max-Age=…;
 *   SameSite=Lax"`) and repaints in place. PHP reads that cookie on the next
 *   request and prints `data-theme` on `<html>` - which is the whole reason
 *   there is no flash of the wrong theme. A POST route would be a second way
 *   to set the same cookie and a second thing to keep in step; there is none.
 *
 *   A CSRF TOKEN ON `/api/quotes`. It has no session and no authority, and the
 *   two controls that do fit the threat are in the controller. The reasoning is
 *   written out in `Http\QuoteApiController`.
 *
 *   ASSET ROUTES. `public/.htaccess` (LiteSpeed) and `public/router.php` (the
 *   dev server) serve anything that exists on disk before PHP is reached. A
 *   stylesheet must never cost a database connection.
 *
 * TRAILING AND DOUBLED SLASHES are 301'd to the canonical form by `Http\Kernel`
 * before any of this runs, so every page has exactly one URL.
 *
 * ------------------------------------------------------------------ THE ADMIN
 *
 * Every admin route is registered THROUGH `Http\Admin\Guard`, and that is what
 * makes this file readable as a security statement rather than a list of URLs:
 *
 *   Guard::page()   a screen. No session -> 302 to the sign-in, never a 403 and
 *                   never a page.
 *   Guard::write()  a form post. Session, CSRF token (and Origin), and a
 *                   per-admin write limit, in that order, before the handler.
 *   Guard::owner()  the same, plus the role. Publishing, resuming prices.
 *
 * The routes NOT behind the guard are the sign-in itself, the code step, the
 * sign-out and the first-run set-up page - each with its own reason written
 * where it is registered. The set-up page is the odd one: it guards itself, and
 * what it is guarded by is the emptiness of the `admins` table.
 *
 * The URLs are `View\Admin\Routes` constants, which is the same list the admin
 * templates print. Neither side may hold a URL the other does not.
 *
 * NOTHING HERE READS A SPREADSHEET. The rate-sheet feature was removed from the
 * product on 2026-09-27: there is no upload route, no mapper, no intake and no
 * imports list, and `/admin/rates/export.csv` is a GET that hands out a file.
 */

use Speedat\Core\App;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Response;
use Speedat\Core\Router;
use Speedat\Domain\Ops\BearerToken;
use Speedat\Http\Admin\AdminUser;
use Speedat\Http\Admin\AnalyticsController;
use Speedat\Http\Admin\Guard;
use Speedat\Http\Admin\HoldController;
use Speedat\Http\Admin\InboxController;
use Speedat\Http\Admin\LoginController;
use Speedat\Http\Admin\OpsController;
use Speedat\Http\Admin\RatesController;
use Speedat\Http\Admin\SecurityController;
use Speedat\Http\Admin\SetupController;
use Speedat\Http\Admin\ShipmentsController;
use Speedat\Http\ContactController;
use Speedat\Http\QuoteApiController;
use Speedat\Http\SeoController;
use Speedat\Http\SiteController;
use Speedat\View\Admin\Routes as Admin;

return static function (Router $router): void {
    $site    = new SiteController();
    $contact = new ContactController($site);
    $quotes  = new QuoteApiController();
    $seo     = new SeoController();

    $login     = new LoginController();
    $setup     = new SetupController();
    $security  = new SecurityController();
    $rates     = new RatesController();
    $hold      = new HoldController();
    $inbox     = new InboxController();
    $shipments = new ShipmentsController();
    $analytics = new AnalyticsController();
    $ops       = new OpsController();

    /* ------------------------------------------------------- the five pages */

    $router->get('/', static fn (App $app): Response => $site->home($app));
    $router->get('/services', static fn (App $app): Response => $site->services($app));
    $router->get('/about', static fn (App $app): Response => $site->about($app));
    $router->get('/contact', static fn (App $app): Response => $site->contact($app));
    $router->get('/faq', static fn (App $app): Response => $site->faq($app));

    // Terms and conditions. A static legal page linked from the footer; it is
    // not one of the five `Domain\Seo\Routes` pages, so it carries the layout's
    // fallback head and stays out of the sitemap while it is still a draft.
    $router->get('/terms', static fn (App $app): Response => $site->terms($app));

    /* ------------------------------------------------------------- the form */

    // The message form. Post/redirect/get: on success this 303s back to the GET
    // above, which draws the thank-you from a one-shot flash, so a reload
    // cannot send the same message twice.
    $router->post('/contact', static fn (App $app): Response => $contact->submit($app));

    /* ---------------------------------------------------- machine endpoints */

    // The quote log. Fired with `keepalive` when Book is tapped; the browser
    // never waits for it, and every number in the row is the engine's.
    $router->post('/api/quotes', static fn (App $app): Response => $quotes->log($app));

    /* ---------------------------------------------------------- signing in */

    /**
     * The three routes with no session behind them.
     *
     * The sign-in page is obviously one. The code step is another: the first
     * factor has passed but there is no session yet, by design (see
     * `Http\Admin\PendingLogin`), so it cannot be behind the guard. And signing
     * out has to work from the code screen - "Start again" posts to it - so it
     * is not behind the guard either. All three still require the CSRF token,
     * which each handler asserts as its first statement.
     */
    $router->get(Admin::LOGIN, static fn (App $app): Response => $login->form($app));
    $router->post(Admin::LOGIN_PASSWORD, static fn (App $app): Response => $login->password($app));
    $router->post(Admin::LOGIN_GOOGLE, static fn (App $app): Response => $login->google($app));
    $router->post(Admin::LOGIN_CODE, static fn (App $app): Response => $login->code($app));
    $router->post(Admin::LOGOUT, static fn (App $app): Response => $login->logout($app));

    /* --------------------------------------------------- the very first run */

    /**
     * THE FOURTH ROUTE WITH NO SESSION BEHIND IT, and the only one that stops
     * existing.
     *
     * A fresh install has no admin row, so there is nobody for the three routes
     * above to sign in - and on a Hostinger Web plan there may be no shell to
     * run `bin/seed.php` from. `/admin/setup` is the way in that needs nothing
     * but a browser: it exists ONLY while `admins` is empty AND
     * `auth.setup_token` is set in config/config.php, it creates exactly one
     * owner, and the row it writes is what makes it a 404 from then on. Both
     * conditions are checked by `Http\Admin\SetupController` as the first
     * statement of each handler, before the CSRF check and before anything is
     * read out of the request. Nothing closes it down afterwards because nothing
     * has to.
     *
     * The path is a constant on the controller rather than in `View\Admin\Routes`
     * with its neighbours; the controller says why, and says where it should
     * move to.
     */
    $router->get(SetupController::PATH, static fn (App $app): Response => $setup->form($app));
    $router->post(SetupController::PATH, static fn (App $app): Response => $setup->create($app));

    /* -------------------------------------------------------- rates editor */

    $router->get(Admin::RATES, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $rates->editor($app, $admin)
    ));

    /**
     * `/admin/rates` IS NOT A PAGE, BUT IT IS AN ADDRESS PEOPLE TYPE.
     *
     * Eleven URLs live under it - save, publish, review, export, the lot - and
     * the editor they all belong to is at `/admin`, for the good reason that it
     * is the admin's home screen. So the one path in the middle of that tree was
     * the only 404 in it: an owner who trimmed `/admin/rates/review` back to
     * something shorter, or who guessed from a bookmark, got the not-found page
     * while every path under it worked.
     *
     * 301 rather than a second registration of the editor, because there is one
     * editor at one URL - the rule `Http\Kernel` enforces for every other page -
     * and a permanent redirect teaches the browser and the bookmark, once.
     *
     * GET only. `Kernel` never redirects a POST for the same reason: a 301 is
     * followed as a GET and would turn a submitted form into a page view.
     */
    $router->get('/admin/rates', static fn (App $app): Response => Response::redirect(
        $app->request->basePath . Admin::RATES,
        301,
    ));

    /**
     * The review screen answers a GET as well as the publish bar's POST.
     * "Review what is about to go live" is a page the owner reloads, links to,
     * and comes back to after a refused publish; the card is rebuilt from the
     * draft every time, so it can never describe a document that has moved.
     */
    $reviewScreen = Guard::page(static fn (App $app, AdminUser $admin): Response => $rates->review($app, $admin));

    $router->get(Admin::REVIEW, $reviewScreen);
    $router->post(Admin::REVIEW, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->reviewAfterSave($app, $admin)
    ));

    /**
     * Two aliases for the editor, because Settings and Website pages are
     * SECTIONS of it rather than pages of their own (`View\Admin\Sections`), and
     * both a bookmark and the browser harness look for them at their own URLs.
     * They render the one editor; the anchors on the page do the rest.
     */
    $router->get('/admin/settings', $reviewScreen);
    $router->get('/admin/content', $reviewScreen);

    $router->post(Admin::SAVE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->save($app, $admin)
    ));
    $router->post(Admin::ADD_DESTINATION, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->addDestination($app, $admin)
    ));
    $router->post(Admin::REMOVE_DESTINATION, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->removeDestination($app, $admin)
    ));
    $router->post(Admin::SORT_DESTINATIONS, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->sort($app, $admin)
    ));
    $router->post(Admin::UNDO_ROW, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->undoRow($app, $admin)
    ));
    $router->post(Admin::FILL, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->fill($app, $admin)
    ));
    $router->post(Admin::BULK, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->bulk($app, $admin)
    ));
    $router->post(Admin::TEST, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->test($app, $admin)
    ));
    $router->post(Admin::DISCARD, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->discard($app, $admin)
    ));
    $router->post(Admin::RESTORE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $rates->restore($app, $admin)
    ));

    // Owner only, server-side. The template also hides the button, which is a
    // courtesy and not the control.
    $router->post(Admin::PUBLISH, Guard::owner(
        static fn (App $app, AdminUser $admin): Response => $rates->publish($app, $admin)
    ));

    /**
     * Rolling back: put an already-published version back on the website in one
     * action, with its own line in the history and its own line in the diary.
     *
     * OWNER, LIKE PUBLISHING, because that is what it is - it publishes. Two
     * posts, not one: the first is the question and writes nothing, the second
     * carries `confirm=1`. See `Http\Admin\RatesController::rollback()`.
     */
    $router->post(Admin::ROLLBACK, Guard::owner(
        static fn (App $app, AdminUser $admin): Response => $rates->rollback($app, $admin)
    ));

    /**
     * The export. Two URLs for one file: `export.csv` is the one the templates
     * link and the one a browser saves with a sensible name; `/admin/rates/export`
     * is what the deploy runbook and the browser harness ask for. Both stream the
     * same bytes through the same handler.
     */
    $exportCsv = Guard::page(static fn (App $app, AdminUser $admin): Response => $rates->exportCsv($app, $admin));

    $router->get(Admin::EXPORT_CSV, $exportCsv);
    $router->get('/admin/rates/export', $exportCsv);

    /* --------------------------------------------------------- price hold */

    // Any admin may take prices off the site; only an owner may put them back,
    // and that half is checked inside the handler because it is one form.
    $router->post(Admin::HOLD, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $hold->set($app, $admin)
    ));

    /* -------------------------------------------------- inbox and the desk */

    $router->get(Admin::INBOX, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $inbox->page($app, $admin)
    ));
    $router->post(Admin::INBOX_UPDATE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $inbox->update($app, $admin)
    ));

    $router->get(Admin::SHIPMENTS, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $shipments->index($app, $admin)
    ));

    // Before the {id} route: "new" is a word, not a shipment number, and the
    // router matches in registration order.
    $router->get(Admin::SHIPMENT_NEW, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $shipments->create($app, $admin)
    ));
    $router->post(Admin::SHIPMENT_CREATE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $shipments->store($app, $admin)
    ));
    $router->post(Admin::SHIPMENT_UPDATE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $shipments->update($app, $admin)
    ));
    $router->post(Admin::SHIPMENT_EVENT, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $shipments->event($app, $admin)
    ));
    $router->get(Admin::SHIPMENTS . '/{id}', Guard::page(
        static fn (App $app, AdminUser $admin, array $params): Response => $shipments->desk($app, $admin, $params['id'] ?? '')
    ));

    /* --------------------------------------------------------- analytics */

    /**
     * The owner's numbers. One GET and nothing else: the screen reads, and
     * everything it can be asked is in the query string (`?days=`, or
     * `?from=&to=`), so it is bookmarkable and a reload is a reload.
     *
     * Behind `Guard::page()` like every other screen. It is not a secret - it
     * is how many people came to a public website - but it is the shape of the
     * owner's business, and there is no reason for a stranger to read it.
     */
    $router->get(Admin::ANALYTICS, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $analytics->page($app, $admin)
    ));

    /* ---------------------------------------------------------- security */

    $router->get(Admin::SECURITY, Guard::page(
        static fn (App $app, AdminUser $admin): Response => $security->page($app, $admin)
    ));
    $router->post(Admin::TOTP_BEGIN, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $security->begin($app, $admin)
    ));
    $router->post(Admin::TOTP_CONFIRM, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $security->confirm($app, $admin)
    ));
    $router->post(Admin::TOTP_DISABLE, Guard::write(
        static fn (App $app, AdminUser $admin): Response => $security->disable($app, $admin)
    ));

    /* ------------------------------------------------------ operational */

    // `Authorization: Bearer <token>`, compared with hash_equals; an unset
    // secret refuses everybody. /api/metrics takes the HEADER AND NOTHING ELSE -
    // it used to advertise `?token=` and, on a default Apache, to accept only
    // that, because the header never reached PHP at all. The other two still
    // take a URL, for the hPanel cron field that cannot send a header. Which,
    // why, and what was measured: Http\Admin\OpsController.
    $router->get('/api/metrics', static fn (App $app): Response => $ops->metrics($app));
    $router->get('/api/cron', static fn (App $app): Response => $ops->cron($app));
    $router->get('/ops/opcache-reset', static fn (App $app): Response => $ops->opcacheReset($app));

    /* ------------------------------------------------------------------ SEO */

    $router->get('/sitemap.xml', static fn (App $app): Response => $seo->sitemap($app));
    $router->get('/robots.txt', static fn (App $app): Response => $seo->robots($app));

    /* ------------------------------------------------------------- plumbing */

    // Somebody types the front controller by hand, or an old link points at
    // it. One canonical URL for the home page, not two.
    $router->get('/index.php', static fn (): Response => Response::redirect('/', 301));

    /**
     * Liveness check for uptime monitoring.
     *
     * Public answer: "the PHP is running". Nothing else - a health endpoint
     * that reports which component is broken is a map for someone deciding
     * what to attack. The detail is behind the metrics token.
     */
    $router->get('/health', static function (App $app): Response {
        // THE DETAIL IS BEHIND THE SAME SECRET /api/metrics USES, so it is
        // behind the same door: `Authorization: Bearer <token>`, never
        // `?token=`. Leaving the query form here would have been a second
        // doorway with the old lock on it - the same secret, written into the
        // access log of every proxy between the monitor and the site, by the
        // endpoint a monitor calls most often of all.
        //
        // `BearerToken::matches()` rather than a comparison written here: it is
        // the application's one constant-time check, it compares the scheme word
        // too, and it refuses an unset secret - which is the direction that
        // matters, because a deployment that forgot `tokens.metrics` must close
        // this detail rather than publish it.
        $detailed = BearerToken::matches(Config::str('tokens.metrics'), OpsController::headerOnly($app));

        if (!$detailed) {
            return Response::json(['ok' => true])->noStore();
        }

        return Response::json([
            'ok'       => true,
            'php'      => PHP_VERSION,
            'env'      => Config::env(),
            'database' => Db::healthy() ? 'up' : 'down',
            'time'     => gmdate('c'),
        ])->noStore();
    });
};
