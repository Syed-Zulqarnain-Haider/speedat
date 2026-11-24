<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\PublishConflict;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Validate;
use Speedat\View\Admin\PagesPage;
use Speedat\View\Admin\Routes;

/**
 * Website text: the words and the search titles on the public pages, published
 * on their own. The whole of a marketer's admin, and a screen the owner has too.
 *
 * WHAT GOES LIVE IS THE LIVE DOCUMENT WITH WORDS CHANGED IN IT. Not the draft:
 * the draft is where the owner's unpublished prices are, so publishing it would
 * put them on the website for a spelling mistake. The document is taken from
 * the published version, `PagesForm` changes only the fields on its list, and
 * nothing else in it can differ - the prices, the company details, the popup,
 * the reviews and the "rates are live" tick are the same objects. The draft row
 * is not touched (`PublishArgs::$keepDraft`) and the price hold is not lifted.
 *
 * IT IS A VERSION LIKE ANY OTHER, so it is in the history with the marketer's
 * address on it and an owner can roll it back.
 */
final class PagesController
{
    /** Publishes per person per hour. Each one is a whole document in `versions`. */
    private const PUBLISH_LIMIT = 60;

    /** GET /admin/pages */
    public function page(App $app, AdminUser $admin): Response
    {
        return $this->render($app, $admin, self::live(), toast: Notice::toast($app), error: Notice::error($app));
    }

    /** POST /admin/pages/publish */
    public function publish(App $app, AdminUser $admin): Response
    {
        RateLimit::enforceKey('pages_publish', $admin->email, self::PUBLISH_LIMIT, 3600);

        $live = self::live();
        $form = PagesForm::of($live, $app->request);

        if ($live->version < 1 || $form->base === null) {
            return $this->render($app, $admin, $live, $form, Notice::text('pagesbase'), status: 409);
        }

        if ($form->problems !== []) {
            return $this->render($app, $admin, $live, $form, implode(' ', $form->problems), status: 422);
        }

        if ($form->changed === []) {
            return $this->back($app, ['nochange' => true]);
        }

        $document = $live->data->withContent($form->content);
        $diff     = Diff::of($live->data, $document);

        if (Validate::errors($document) !== []) {
            return $this->render($app, $admin, $live, $form, Notice::text('publish'), status: 422);
        }

        try {
            $published = Repo::publishVersion(new PublishArgs(
                data: $document,
                by: $admin->email,
                source: PublishArgs::PAGES,
                summary: 'Website text: ' . Diff::summarise($diff),
                changeCount: $diff->count,
                // The version this document was built from has to still be the
                // live one, or the prices in it may already be old.
                expectedBase: $live->version,
                keepDraft: true,
            ));
        } catch (PublishConflict $e) {
            Log::warn('pages_publish_conflict', ['live' => $e->live, 'built' => $live->version]);

            return $this->render($app, $admin, self::live(), $form, Notice::text('pagesraced'), status: 409);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Log::exception('pages_publish_failed', $e, ['version' => $live->version + 1]);

            return $this->render($app, $admin, $live, $form, Notice::text('publish'), status: 503);
        }

        Audit::log($admin->email, 'pages_published', [
            'version' => $published->version,
            'role'    => $admin->role,
            'fields'  => $form->changed,
            'changes' => array_map(static fn ($line): array => $line->toArray(), array_slice($diff->lines, 0, 60)),
        ], $app->request);

        return $this->back($app, ['pageslive' => $published->version]);
    }

    /* ------------------------------------------------------------ internals */

    private static function live(): PublishedVersion
    {
        return Repo::getLatestVersion() ?? PublishedVersion::sample();
    }

    private function render(
        App $app,
        AdminUser $admin,
        PublishedVersion $live,
        ?PagesForm $form = null,
        string $error = '',
        string $toast = '',
        int $status = 200,
    ): Response {
        return $app->page('admin/pages', [
            'page' => new PagesPage(
                live: $live,
                typed: $form?->typed ?? [],
                // A refused form keeps the version it was opened at: its
                // unchanged boxes are still that version's words.
                base: $form?->base ?? $live->version,
                canRollBack: $admin->isOwner(),
                error: $error,
                toast: $toast,
            ),
            'shell' => Screen::shell($admin, 'pages'),
            'theme' => Screen::theme($app),
        ], $status);
    }

    /** @param array<string,int|string|bool> $codes */
    private function back(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::PAGES, $codes), 303)->noStore();
    }
}
