<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Auth\FirebaseBrowser;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\UsersPage;

/**
 * Logins: who may sign in, and adding or removing MARKETERS. Owner only.
 *
 * IT CAN ONLY MAKE AND UNMAKE MARKETERS. Not admins, not editors, and it cannot
 * change anybody's role: a stolen owner session must not be able to mint
 * another owner who is still there after the session is gone. Adding an admin
 * stays the deliberate database change the Security page describes.
 *
 * THERE ARE NO PASSWORDS HERE. A marketer signs in with Google; the row is the
 * permission and Google is the proof, so nobody in this office ever knows or
 * sends somebody else's password.
 */
final class UsersController
{
    /** GET /admin/users */
    public function page(App $app, AdminUser $admin): Response
    {
        return $app->page('admin/users', [
            'page' => new UsersPage(
                viewerEmail: $admin->email,
                users: Admins::all(),
                googleReady: FirebaseBrowser::config() !== null,
                passwordsOn: Config::bool('auth.allow_password_login', false),
                error: Notice::error($app),
                toast: Notice::toast($app),
            ),
            'shell' => Screen::shell($admin, 'users'),
            'theme' => Screen::theme($app),
        ]);
    }

    /** POST /admin/users/add */
    public function add(App $app, AdminUser $admin): Response
    {
        $email = Str::email($app->request->post('email', '', 400));
        $name  = Str::squish($app->request->post('name', '', 120));

        if ($email === '' || !Str::isEmail($email) || mb_strlen($email) > 190) {
            return $this->back($app, ['err' => 'bademail']);
        }

        if (Admins::row($email) !== null) {
            return $this->back($app, ['err' => 'userexists']);
        }

        try {
            Admins::addMarketer($email, $name, $admin->email);
        } catch (\Throwable $e) {
            // The ENUM without "marketer" is the one to expect: the database
            // update was not imported. The sentence says so.
            Log::exception('marketer_add_failed', $e, ['email' => Str::maskEmail($email)]);

            return $this->back($app, ['err' => 'nomarketer']);
        }

        Audit::log($admin->email, 'marketer_added', ['email' => $email], $app->request);

        return $this->back($app, ['useradded' => true]);
    }

    /** POST /admin/users/remove */
    public function remove(App $app, AdminUser $admin): Response
    {
        $email = Str::email($app->request->post('email', '', 190));

        if (!Admins::removeMarketer($email)) {
            return $this->back($app, ['err' => 'nouser']);
        }

        Audit::log($admin->email, 'marketer_removed', ['email' => $email], $app->request);

        return $this->back($app, ['userremoved' => true]);
    }

    /** @param array<string,int|string|bool> $codes */
    private function back(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::USERS, $codes), 303)->noStore();
    }
}
