<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Response;
use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\SiteDoc\Content;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Http\Page;
use Speedat\View\Admin\Routes;
use Speedat\View\Marketing\PageEditor;
use Speedat\View\Marketing\Tabs;

/**
 * The marketers' own area, /marketing: their editor, one website page at a
 * time. Nothing here decides what may be written - that is `PagesForm` - and
 * publishing is `PagesPublisher`, the same one the admin's screen uses.
 */
final class MarketingController
{
    private const RECENT = 8;

    /** GET /marketing */
    public function page(App $app, AdminUser $admin): Response
    {
        return $this->render($app, $admin, PagesPublisher::live(), Tabs::key($app->request->query('page', '', 20)));
    }

    /** POST /marketing/publish */
    public function publish(App $app, AdminUser $admin): Response
    {
        $tab    = Tabs::key($app->request->post('page', '', 20));
        $result = PagesPublisher::publish($app, $admin);

        if ($result->refused !== '') {
            return $this->render($app, $admin, $result->live, $tab, $result);
        }

        $codes = $result->version > 0 ? ['pageslive' => $result->version] : ['nochange' => true];

        return Response::redirect(
            $app->request->basePath . Notice::url(Routes::MARKETING, $codes) . '&page=' . rawurlencode($tab),
            303,
        )->noStore();
    }

    private function render(App $app, AdminUser $admin, PublishedVersion $live, string $tab, ?PagesResult $result = null): Response
    {
        [$title, $description, $address] = $this->automatic($app, $live, $tab);

        return $app->page('marketing/editor', [
            'page' => new PageEditor(
                live: $live,
                tab: $tab,
                viewerEmail: $admin->email,
                autoTitle: $title,
                autoDescription: $description,
                address: $address,
                typed: $result?->form?->typed ?? [],
                base: $result?->form?->base ?? $live->version,
                recent: Repo::pagesHistory(self::RECENT),
                error: $result?->refused ?? Notice::error($app),
                notice: Notice::toast($app),
            ),
            'admin' => $admin,
            'theme' => Screen::theme($app),
        ], $result?->status ?? 200);
    }

    /**
     * What a search engine is given for this page while its boxes are blank.
     *
     * @return array{0:string,1:string,2:string} title, description, address
     */
    private function automatic(App $app, PublishedVersion $live, string $tab): array
    {
        $route = Tabs::route($tab);
        $url   = $route === '' ? null : Page::url($app);

        if ($url === null) {
            return ['', '', ''];
        }

        $blank = $live->data->content;
        foreach (Content::SEO_FIELDS as $field) {
            $blank = $blank->with($field, '');
        }

        $head = Head::forKey(new SiteFacts($live->data->withContent($blank), $live->version, $live->publishedAt, Page::doc()->pricesVisible()), $url, $route);
        $host = (string) parse_url($head->canonical(), PHP_URL_HOST);
        $path = trim(Tabs::path($tab), '/');

        return [$head->title(), $head->description(), $host . ($path === '' ? '' : " \u{203a} " . $path)];
    }
}
