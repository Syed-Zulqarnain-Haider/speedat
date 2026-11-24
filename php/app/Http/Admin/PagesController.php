<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Response;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\View\Admin\PagesPage;
use Speedat\View\Admin\Routes;

/**
 * The admin's own Website text screen: the same words a marketer has, all on
 * one page. Publishing is `PagesPublisher`, which says what may go live and why.
 */
final class PagesController
{
    /** GET /admin/pages */
    public function page(App $app, AdminUser $admin): Response
    {
        return $this->render($app, $admin, PagesPublisher::live());
    }

    /** POST /admin/pages/publish */
    public function publish(App $app, AdminUser $admin): Response
    {
        $result = PagesPublisher::publish($app, $admin);

        if ($result->refused !== '') {
            return $this->render($app, $admin, $result->live, $result);
        }

        $codes = $result->version > 0 ? ['pageslive' => $result->version] : ['pagessame' => true];

        return Response::redirect($app->request->basePath . Notice::url(Routes::PAGES, $codes), 303)->noStore();
    }

    private function render(App $app, AdminUser $admin, PublishedVersion $live, ?PagesResult $result = null): Response
    {
        return $app->page('admin/pages', [
            'page' => new PagesPage(
                live: $live,
                typed: $result?->form->typed ?? [],
                // A refused form keeps its own base - even one nobody can find. Given
                // the live number, a second press would put every old box over
                // somebody else's words.
                base: $result === null ? $live->version : ($result->form->base ?? 0),
                error: $result?->refused ?? Notice::error($app),
                toast: Notice::toast($app),
            ),
            'shell' => Screen::shell($admin, 'pages'),
            'theme' => Screen::theme($app),
        ], $result?->status ?? 200);
    }
}
